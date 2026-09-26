import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Minimal DOM and deferred transport doubles; real browser coverage is separate.
function harness(){
  const handlers={}, requests=[];
  class Element {
    constructor(tag='div'){ this.tagName=tag.toUpperCase();this.children=[];this.attrs={};this.value='';this.disabled=false;this.hidden=false;this.textContent='';this.style={}; }
    setAttribute(k,v){this.attrs[k]=String(v);}
    getAttribute(k){return this.attrs[k] ?? null;}
    hasAttribute(k){return k in this.attrs;}
    removeAttribute(k){delete this.attrs[k];}
    appendChild(el){this.children.push(el);el.parent=this;return el;}
    get options(){return this.children;}
    get selectedIndex(){return this.children.findIndex(el=>el.value===this.value);}
    set innerHTML(v){this.children=[];this.value='';if(v.includes('<option')) {const e=new Element('option');this.children.push(e);}}
    get innerHTML(){return '';}
    focus(){this.focused=true;}
    closest(sel){return sel==='[data-wpcb-booking-form]' ? this.form : null;}
    matches(sel){return sel==='[data-wpcb-type-select]' ? this===this.form?.type : sel==='[data-wpcb-party-size]' ? this===this.form?.party : sel==='[data-wpcb-slot-select]' ? this===this.form?.slots : false;}
  }
  function form(){
    const f=new Element('form'); f.form=f; f.type=new Element('select');f.slots=new Element('select');f.party=new Element('input');f.submit=new Element('button');
    f.party.value='1'; f.submit.attrs.type='submit';
    for(const [id,disabled] of [['A',false],['B',false],['blocked',true]]){let o=new Element('option');o.value=id;o.text=id;o.disabled=disabled;o.attrs['data-capacity']='5';f.type.appendChild(o);}
    for(const el of [f.type,f.slots,f.party,f.submit]) el.form=f;
    f.querySelector=(sel)=>({'[data-wpcb-type-select]':f.type,'[data-wpcb-slot-select]':f.slots,'[data-wpcb-party-size]':f.party,'[name="party_size"]':f.party}[sel] || f.children.find(el=>el.hasAttribute(sel.slice(1,-1))) || null);
    f.querySelectorAll=(sel)=>sel==='button[type="submit"], input[type="submit"]' ? [f.submit] : [];
    return f;
  }
  const forms=[form(),form()];
  const document={addEventListener:(n,f)=>handlers[n]=f,createElement:(tag)=>new Element(tag),querySelector:()=>null,querySelectorAll:()=>forms};
  const ctx={document,window:{wpcbFrontend:{ajaxUrl:'/ajax',nonce:'test',i18n:{}}},URLSearchParams,AbortController,console};ctx.wpcbFrontend=ctx.window.wpcbFrontend;
  ctx.fetch=(url,opts)=>new Promise((resolve,reject)=>requests.push({url,opts,resolve,reject}));
  vm.runInNewContext(readFileSync('assets/js/frontend.js','utf8'),ctx);
  const change=(f,type)=>{f.type.value=type;handlers.change({target:f.type});};
  const answer=(i,token)=>requests[i].resolve({ok:true,json:async()=>({success:true,data:{slots:[{value:token,label:token}]}})});
  const tick=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
  return {handlers,forms,requests,change,answer,tick};
}
test('recovered signed slot survives initial boot without an unnecessary replacement request',()=>{const h=harness(),f=h.forms[0];f.type.value='A';f.slots.value='signed-recovery-token';f.slots.setAttribute('data-wpcb-recovered-slot','1');h.handlers.DOMContentLoaded();assert.equal(h.requests.length,0);assert.equal(f.slots.value,'signed-recovery-token');assert.equal(f.submit.disabled,false);});
test('obsolete type success cannot overwrite a newer selection',async()=>{const h=harness(),f=h.forms[0];h.change(f,'A');h.change(f,'B');h.answer(1,'B-token');await h.tick();h.answer(0,'A-token');await h.tick();assert.deepEqual(f.slots.options.map(x=>x.value),['','B-token']);});
test('obsolete failure cannot erase newer slots',async()=>{const h=harness(),f=h.forms[0];h.change(f,'A');h.change(f,'B');h.answer(1,'B-token');await h.tick();h.requests[0].reject(new Error('old'));await h.tick();assert.ok(f.slots.options.some(x=>x.value==='B-token'));});
test('clearing the type does not fetch type zero and invalidates old work',async()=>{const h=harness(),f=h.forms[0];h.change(f,'A');h.change(f,'');assert.equal(h.requests.length,1);h.answer(0,'old');await h.tick();assert.equal(f.slots.value,'');assert.equal(f.submit.disabled,true);});
test('different forms have independent generations',async()=>{const h=harness();h.change(h.forms[0],'A');h.change(h.forms[1],'B');h.answer(0,'a');h.answer(1,'b');await h.tick();assert.ok(h.forms[0].slots.options.some(x=>x.value==='a'));assert.ok(h.forms[1].slots.options.some(x=>x.value==='b'));});
test('party-size input immediately invalidates a previous selection',async()=>{const h=harness(),f=h.forms[0];h.change(f,'A');h.answer(0,'old');await h.tick();f.slots.value='old';f.party.value='2';h.handlers.input({target:f.party});assert.equal(f.slots.value,'');assert.equal(f.submit.disabled,true);});
test('disabled payment choices do not start a request',()=>{const h=harness();h.change(h.forms[0],'blocked');assert.equal(h.requests.length,0);assert.equal(h.forms[0].submit.disabled,true);});
test('provider errors differ from an empty successful response and offer retry',async()=>{const h=harness(),f=h.forms[0];h.change(f,'A');h.requests[0].resolve({ok:false,json:async()=>({success:false,data:{message:'Payment unavailable <b>safe text</b>'}})});await h.tick();const status=f.querySelector('[data-wpcb-slot-status]');assert.equal(status.textContent,'Payment unavailable <b>safe text</b>');assert.equal(f.querySelector('[data-wpcb-slot-retry]').hidden,false);assert.equal(f.submit.disabled,true);});
test('submission while loading is blocked',()=>{const h=harness(),f=h.forms[0];h.change(f,'A');let prevented=false;h.handlers.submit({target:f,preventDefault:()=>prevented=true});assert.equal(prevented,true);});
