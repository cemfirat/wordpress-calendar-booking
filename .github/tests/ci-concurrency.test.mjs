import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// Offline contract checks against the real workflow, not a copied policy.
// This evaluates only the small expression subset used below; it is NOT a
// YAML parser or a substitute for observing GitHub's scheduler at runtime.
const source = readFileSync(
  process.env.WPCB_CI_WORKFLOW || new URL('../workflows/ci.yml', import.meta.url),
  'utf8',
);
const header = source.split(/^jobs:\s*$/m)[0];
const blocks = [...header.matchAll(/^concurrency:\n((?:[ \t]+[^\n]*\n|\n)*)/gm)];
assert.equal(blocks.length, 1, 'Expected one workflow-level concurrency block');

function scalar(key) {
  const matches = [...blocks[0][1].matchAll(new RegExp(`^  ${key}: (.+)$`, 'gm'))];
  assert.equal(matches.length, 1, `Expected one inline concurrency.${key}`);
  return matches[0][1].trim();
}

function property(path, github) {
  assert.match(path, /^github(?:\.[A-Za-z_][A-Za-z_0-9]*)+$/, 'Unsupported property');
  return path.split('.').slice(1).reduce((value, key) => value?.[key], github) ?? '';
}

function evaluate(expression, github) {
  const equality = expression.match(/^(github(?:\.[A-Za-z_][A-Za-z_0-9]*)+) == '([^']*)'$/);
  if (equality) {
    return String(property(equality[1], github)).toLowerCase() === equality[2].toLowerCase();
  }
  // The fixture values here are strings, positive numbers, or missing values.
  const alternatives = expression.split(/\s+\|\|\s+/).map(path => property(path, github));
  return alternatives.find(Boolean) ?? '';
}

function policy(github) {
  const group = scalar('group').replace(/\$\{\{\s*(.*?)\s*\}\}/g,
    (_, expression) => String(evaluate(expression, github)));
  assert.ok(!group.includes('${{'), 'Unparsed group expression');
  const cancel = scalar('cancel-in-progress');
  const expression = cancel.match(/^\$\{\{\s*(.*?)\s*\}\}$/);
  assert.ok(expression || cancel === 'true' || cancel === 'false', 'Invalid cancel condition');
  return {
    // GitHub compares concurrency groups case-insensitively.
    group: group.toLowerCase(),
    cancel: expression ? evaluate(expression[1], github) : cancel === 'true',
  };
}

function context({ event = 'pull_request', pr = 149, run = 1001,
  branch = 'fix', repo = 'alice/calendar-fork', workflow = 'CI' } = {}) {
  return {
    workflow, event_name: event, run_id: run,
    ref: event === 'pull_request' ? `refs/pull/${pr}/merge` : 'refs/heads/main',
    head_ref: event === 'pull_request' ? branch : '',
    event: event === 'pull_request' ? {
      pull_request: { number: pr, head: { ref: branch, repo: { full_name: repo } } },
    } : {},
  };
}

const group = options => policy(context(options)).group;

test('successive commits in the same PR share a group and allow cancellation', () => {
  assert.equal(group({ run: 1001 }), group({ run: 1002 }));
  assert.equal(policy(context()).cancel, true);
});

test('different fork PRs with identical branch names remain isolated', () => {
  assert.notEqual(group({ pr: 149, repo: 'alice/fork' }),
    group({ pr: 150, repo: 'bob/fork' }));
});

test('case-insensitive branch-name collisions cannot cross PRs', () => {
  assert.notEqual(group({ pr: 149, branch: 'Fix' }), group({ pr: 150, branch: 'fix' }));
});

test('PR identity does not depend on the source branch name', () => {
  assert.equal(group({ branch: 'fix', run: 1001 }), group({ branch: 'renamed', run: 1002 }));
});

test('different workflows remain isolated', () => {
  assert.notEqual(group({ workflow: 'CI' }), group({ workflow: 'Other checks' }));
});

for (const event of ['push', 'workflow_dispatch']) {
  test(`${event} runs on main have unique groups, even with three pending revisions`, () => {
    assert.equal(new Set([1001, 1002, 1003].map(run => group({ event, run }))).size, 3);
    assert.equal(policy(context({ event })).cancel, false);
  });
}

test('a PR number cannot collide with a non-PR run ID', () => {
  const prGroup = group({ pr: 149, branch: '149', run: 1001 });
  for (const event of ['push', 'workflow_dispatch']) {
    assert.notEqual(prGroup, group({ event, run: 149 }));
  }
});

test('missing PR metadata fails safe with per-run isolation', () => {
  const first = context({ run: 1001 });
  const second = context({ run: 1002 });
  first.event = {};
  second.event = {};
  assert.notEqual(policy(first).group, policy(second).group);
});

test('the release job retains its independent non-cancelling policy', () => {
  const release = source.match(/^  release:\n([\s\S]*?)(?=^  [A-Za-z_]+:|(?![\s\S]))/m);
  assert.ok(release, 'Release job must exist');
  assert.match(release[1], /    concurrency:\n      group: calendar-booking-release\n      cancel-in-progress: false\n/);
  assert.match(release[1], /    if: github\.ref == 'refs\/heads\/main' && github\.event_name != 'pull_request'/);
});
