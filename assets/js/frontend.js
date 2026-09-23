(function(){
  function closest(el, sel){ return el && el.closest ? el.closest(sel) : null; }
  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function isPhoneType(text){ text=(text||'').toLowerCase(); return text.indexOf('telefon')!==-1; }
  function isVisitType(text){ text=(text||'').toLowerCase(); return text.indexOf('besuch')!==-1 || text.indexOf('vor ort')!==-1; }

  function updateConditional(form){
    if(!form) return;
    var typeSelect = q('[data-wpcb-type-select]', form);
    var typeText = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex].text : '';
    var whoCalls = q('[name="who_calls"]', form);
    var phoneField = closest(q('[name="phone"]', form), '.wpcb-field');
    var locationField = closest(q('[name="location"]', form), '.wpcb-field');
    var location = q('[name="location"]', form);
    var ownPhone = q('[name="own_phone_value"]', form) ? q('[name="own_phone_value"]', form).value : '';
    var visitAddress = q('[name="visit_address_value"]', form) ? q('[name="visit_address_value"]', form).value : '';

    if(locationField){
      if(isVisitType(typeText)){
        locationField.style.display='';
        if(location){ location.value = visitAddress; location.readOnly = true; }
      } else {
        locationField.style.display='';
        if(location && location.readOnly && location.value === visitAddress){ location.value=''; }
        if(location){ location.readOnly = false; }
      }
    }
    if(whoCalls){
      var wrapper = closest(whoCalls, '.wpcb-field');
      if(wrapper) wrapper.style.display = isPhoneType(typeText) ? '' : 'none';
    }
    if(phoneField){
      if(isPhoneType(typeText)){
        phoneField.style.display='';
        var phoneInput = q('[name="phone"]', form);
        var callsVal = whoCalls ? whoCalls.value : '';
        if(phoneInput){
          if(callsVal === 'Ich rufe an'){
            phoneInput.value = ownPhone;
            phoneInput.readOnly = true;
          } else {
            if(phoneInput.value === ownPhone) phoneInput.value='';
            phoneInput.readOnly = false;
          }
        }
      }
    }
  }

  function focusDialog(modal){
    var target = q('[data-wpcb-modal-panel]', modal) || q('[data-wpcb-close-modal]', modal);
    if(target && target.focus) target.focus();
  }

  function showModal(modal, trigger){
    if(trigger) modal.__wpcbTrigger = trigger;
    if(window.UIkit && UIkit.modal){
      modal.removeAttribute('hidden');
      var inst = UIkit.modal(modal);
      inst.show();
      window.setTimeout(function(){ focusDialog(modal); }, 0);
      return;
    }
    modal.hidden = false;
    document.documentElement.classList.add('wpcb-modal-open');
    focusDialog(modal);
  }

  function hideModal(modal){
    var trigger = modal.__wpcbTrigger;
    var restoreFocus = function(){
      if(trigger && trigger.focus) trigger.focus();
    };
    if(window.UIkit && UIkit.modal){
      var inst = UIkit.modal(modal);
      var restored = false;
      var restoreOnce = function(){
        if(restored) return;
        restored = true;
        restoreFocus();
      };
      modal.addEventListener('hidden', restoreOnce, {once:true});
      inst.hide();
      // UIkit restores/touches focus during its hide transition. Keep a
      // bounded fallback so the original trigger wins even if the theme uses
      // a UIkit build that does not emit the native hidden event here.
      window.setTimeout(restoreOnce, 350);
    } else {
      modal.hidden = true;
      document.documentElement.classList.remove('wpcb-modal-open');
      window.setTimeout(restoreFocus, 0);
    }
  }

  function fillSlots(form, typeId, preselect){
    var select = q('[data-wpcb-slot-select]', form);
    if(!select) return;
    select.innerHTML = '<option value="">Lade freie Zeiten ...</option>';
    var body = new URLSearchParams();
    body.set('action','wpcb_get_slots');
    body.set('nonce', (window.wpcbFrontend && wpcbFrontend.nonce) || '');
    body.set('type_id', typeId || '');
    var partySize = q('[data-wpcb-party-size]', form);
    body.set('party_size', partySize ? partySize.value || '1' : '1');
    fetch((window.wpcbFrontend && wpcbFrontend.ajaxUrl) || '/wp-admin/admin-ajax.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(r){ return r.json(); }).then(function(json){
      if(!json || !json.success){
        select.innerHTML = '<option value="">Keine freien Zeiten gefunden</option>';
        return;
      }
      var items = json.data && json.data.slots ? json.data.slots : [];
      if(!items.length){
        select.innerHTML = '<option value="">Keine freien Zeiten gefunden</option>';
        return;
      }
      select.innerHTML = '<option value="">Bitte wählen</option>';
      items.forEach(function(slot){
        var opt = document.createElement('option');
        opt.value = slot.value;
        opt.textContent = slot.label;
        if(preselect && preselect === slot.value) opt.selected = true;
        select.appendChild(opt);
      });
    }).catch(function(){
      select.innerHTML = '<option value="">Fehler beim Laden der Zeiten</option>';
    });
  }

  document.addEventListener('change', function(e){
    var form = closest(e.target, '[data-wpcb-booking-form]');
    if(!form) return;
    if(e.target.matches('[data-wpcb-type-select]')){
      var partyInput = q('[data-wpcb-party-size]', form);
      var selected = e.target.options[e.target.selectedIndex];
      if(partyInput && selected){
        var cap = parseInt(selected.getAttribute('data-capacity') || '1', 10);
        partyInput.max = String(Math.max(1, cap));
        if(parseInt(partyInput.value || '1', 10) > cap) partyInput.value = String(Math.max(1, cap));
      }
      fillSlots(form, e.target.value, '');
    }
    if(e.target.matches('[data-wpcb-party-size]')){
      var typeSelect = q('[data-wpcb-type-select]', form);
      if(typeSelect && typeSelect.value) fillSlots(form, typeSelect.value, '');
    }
    updateConditional(form);
  });

  document.addEventListener('click', function(e){
    var open = closest(e.target, '[data-wpcb-open-toolbar-modal]');
    if(open){
      e.preventDefault();
      var wrap = closest(open, '[data-wpcb-booking-calendar]') || document;
      var modal = q('[data-wpcb-modal]', wrap);
      if(!modal) return;
      showModal(modal, open);
      return;
    }
    var close = closest(e.target, '[data-wpcb-close-modal]');
    if(close){
      e.preventDefault();
      var modal2 = closest(close, '[data-wpcb-modal]');
      if(modal2) hideModal(modal2);
    }
  });

  document.addEventListener('keydown', function(e){
    if(e.key !== 'Escape') return;
    var modal = q('[data-wpcb-modal]:not([hidden])');
    if(modal){
      e.preventDefault();
      hideModal(modal);
    }
  });

  document.addEventListener('DOMContentLoaded', function(){
    qa('[data-wpcb-booking-form]').forEach(function(form){
      updateConditional(form);
    });
  });
})();
