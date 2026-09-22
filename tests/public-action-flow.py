#!/usr/bin/env python3
"""End-to-end GET-safe / POST-only public booking action test."""
import html
import json
import os
import pathlib
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

workspace = pathlib.Path(os.environ["GITHUB_WORKSPACE"])
wp_root = pathlib.Path(os.environ["RUNNER_TEMP"]) / "wordpress"
helper = workspace / "tests/public-action-fixture.php"
base = "http://127.0.0.1:8080/"


def wp_fixture(action, booking_id=None):
    env = os.environ.copy()
    env["CEMB_PUBLIC_FIXTURE_ACTION"] = action
    if booking_id is not None:
        env["CEMB_PUBLIC_BOOKING_ID"] = str(booking_id)
    out = subprocess.check_output(
        ["wp", "eval-file", str(helper), f"--path={wp_root}"],
        env=env,
        text=True,
    ).strip()
    return json.loads(out.splitlines()[-1])


def get(url):
    with urllib.request.urlopen(url, timeout=20) as response:
        return response.read().decode("utf-8"), response.geturl()


def post(fields, expect_error=None):
    data = urllib.parse.urlencode(fields).encode()
    request = urllib.request.Request(
        base + "wp-admin/admin-post.php",
        data=data,
        method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.read().decode("utf-8"), response.geturl(), response.status
    except urllib.error.HTTPError as error:
        if expect_error is None or error.code != expect_error:
            raise
        return error.read().decode("utf-8"), error.geturl(), error.code


def hidden(page, name):
    pattern = r'name=["\']' + re.escape(name) + r'["\'][^>]*value=["\']([^"\']*)'
    match = re.search(pattern, page, re.I)
    assert match, f"Missing hidden field {name}"
    return html.unescape(match.group(1))


def slot_value(page):
    select = re.search(
        r'<select[^>]+name=["\']new_slot_token["\'][^>]*>(.*?)</select>',
        page,
        re.I | re.S,
    )
    assert select, "Missing reschedule slot select"
    values = re.findall(r'<option[^>]+value=["\']([^"\']*)', select.group(1), re.I)
    values = [html.unescape(value) for value in values if value]
    assert values, "No alternate canonical slot token rendered"
    return values[0]


def assert_state(booking_id, status=None, slot_start=None):
    state = wp_fixture("state", booking_id)
    assert not state.get("missing")
    if status is not None:
        assert state["status"] == status, state
    if slot_start is not None:
        assert state["slot_start"] == slot_start, state
    return state


log = open(pathlib.Path(os.environ["RUNNER_TEMP"]) / "cemb-public-http.log", "w")
server = subprocess.Popen(
    ["php", "-S", "127.0.0.1:8080", "-t", str(wp_root)],
    stdout=log,
    stderr=log,
)
try:
    for attempt in range(50):
        try:
            get(base)
            break
        except Exception:
            if server.poll() is not None or attempt == 49:
                raise
            time.sleep(0.2)

    # Double Opt-In: GET and failed CSRF are read-only; valid POST confirms once.
    confirm = wp_fixture("create_confirm")
    before = assert_state(confirm["booking_id"], "reserved_unconfirmed")
    page, _ = get(confirm["url"])
    assert "Terminbuchung bestätigen" in page
    assert_state(confirm["booking_id"], "reserved_unconfirmed", before["slot_start"])

    post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "confirm",
            "cemb_token": confirm["token"],
        },
        expect_error=403,
    )
    assert_state(confirm["booking_id"], "reserved_unconfirmed", before["slot_start"])

    nonce = hidden(page, "cemb_action_nonce")
    result_page, _, status = post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "confirm",
            "cemb_token": confirm["token"],
            "cemb_action_nonce": nonce,
        }
    )
    assert status == 200
    assert "E-Mail bereits bestätigt" in result_page
    assert_state(confirm["booking_id"], "confirmed", before["slot_start"])
    repeated, _ = get(confirm["url"])
    assert "E-Mail bereits bestätigt" in repeated
    assert_state(confirm["booking_id"], "confirmed", before["slot_start"])
    print("PASS: GET/CSRF-safe Double Opt-In changes state only on valid POST.")

    # Cancellation: scanners can GET safely; POST consumes the token once.
    cancel = wp_fixture("create_cancel")
    cancel_before = assert_state(cancel["booking_id"], "confirmed")
    cancel_page, _ = get(cancel["url"])
    assert "Termin stornieren" in cancel_page
    assert_state(cancel["booking_id"], "confirmed", cancel_before["slot_start"])

    cancel_nonce = hidden(cancel_page, "cemb_action_nonce")
    cancelled_page, _, _ = post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "cancel",
            "cemb_token": cancel["token"],
            "cemb_action_nonce": cancel_nonce,
        }
    )
    assert "Termin bereits storniert" in cancelled_page
    assert_state(cancel["booking_id"], "cancelled", cancel_before["slot_start"])
    repeated_cancel, _ = get(cancel["url"])
    assert "Termin bereits storniert" in repeated_cancel
    print("PASS: Cancellation is POST-only and used tokens render read-only status.")

    # Reschedule: canonical slot token is required and the lifecycle state stays confirmed.
    update = wp_fixture("create_update")
    update_before = assert_state(update["booking_id"], "confirmed")
    update_page, _ = get(update["url"])
    assert "Termin ändern" in update_page
    update_nonce = hidden(update_page, "cemb_action_nonce")
    new_slot_token = slot_value(update_page)
    assert_state(update["booking_id"], "confirmed", update_before["slot_start"])

    post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "update",
            "cemb_token": update["token"],
            "new_slot_token": new_slot_token,
        },
        expect_error=403,
    )
    assert_state(update["booking_id"], "confirmed", update_before["slot_start"])

    updated_page, _, _ = post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "update",
            "cemb_token": update["token"],
            "cemb_action_nonce": update_nonce,
            "new_slot_token": new_slot_token,
        }
    )
    update_after = assert_state(update["booking_id"], "confirmed")
    assert update_after["slot_start"] != update_before["slot_start"], (update_before, update_after)
    assert "Änderungslink bereits verwendet" in updated_page

    post(
        {
            "action": "cemb_booking_action",
            "cemb_link_action": "update",
            "cemb_token": update["token"],
            "cemb_action_nonce": update_nonce,
            "new_slot_token": new_slot_token,
        }
    )
    assert_state(update["booking_id"], "confirmed", update_after["slot_start"])
    print("PASS: Reschedule requires POST + nonce + canonical slot and consumes the link once.")

    # Expired links are status-only and never mutate the booking.
    expired = wp_fixture("create_expired")
    expired_before = assert_state(expired["booking_id"], "reserved_unconfirmed")
    expired_page, _ = get(expired["url"])
    assert "Link abgelaufen" in expired_page
    assert_state(expired["booking_id"], "reserved_unconfirmed", expired_before["slot_start"])
    print("PASS: Expired links render a non-destructive status screen.")

finally:
    server.terminate()
    try:
        server.wait(timeout=5)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait()
    log.close()
