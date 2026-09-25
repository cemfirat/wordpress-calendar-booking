(function(){
  function closest(el, sel){ return el && el.closest ? el.closest(sel) : null; }
  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function msg(key, fallback){
    return (window.wpcbFrontend && wpcbFrontend.i18n && wpcbFrontend.i18n[key]) || fallback;
  }
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

  function slotContext(form){
    var type = q('[data-wpcb-type-select]', form);
    var party = q('[name="party_size"]', form);
    return (type ? type.value : '') + '|' + (party ? party.value : '1');
  }

  function slotStatus(form, text, retry){
    var status = q('[data-wpcb-slot-status]', form);
    if(!status){
      status = document.createElement('p');
      status.setAttribute('data-wpcb-slot-status', '');
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      form.appendChild(status);
    }
    status.textContent = text;
    var button = q('[data-wpcb-slot-retry]', form);
    if(!button){
      button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-wpcb-slot-retry', '');
      button.className = 'uk-button uk-button-default';
      button.textContent = msg('retrySlots', 'Try again');
      form.appendChild(button);
    }
    button.hidden = !retry;
  }

  function placeholder(select, text){
    select.innerHTML = '';
    select.value = '';
    var option = document.createElement('option');
    option.value = '';
    option.textContent = text;
    select.appendChild(option);
  }

  function updateSubmit(form){
    var state = form.__wpcbSlots;
    var select = q('[data-wpcb-slot-select]', form);
    var valid = state && !state.pending && state.context === slotContext(form)
      && select && !select.disabled && select.value !== '';
    qa('button[type="submit"], input[type="submit"]', form).forEach(function(button){
      button.disabled = !valid || button.hasAttribute('data-wpcb-payment-blocked');
    });
    return !!valid;
  }

  function fillSlots(form, typeId, preselect){
    var select = q('[data-wpcb-slot-select]', form);
    if(!select) return;
    var previous = form.__wpcbSlots;
    if(previous && previous.controller) previous.controller.abort();
    var state = {
      context: slotContext(form), pending: true,
      controller: typeof AbortController === 'function' ? new AbortController() : null
    };
    form.__wpcbSlots = state;
    select.disabled = true;
    placeholder(select, msg('loadingSlots','Loading available times ...'));
    updateSubmit(form);
    var type = q('[data-wpcb-type-select]', form);
    var choice = type && type.options[type.selectedIndex];
    if(!typeId || !choice || choice.disabled){
      state.pending = false;
      placeholder(select, msg('choose','Please choose'));
      slotStatus(form, '', false);
      updateSubmit(form);
      return;
    }
    slotStatus(form, msg('loadingSlots','Loading available times ...'), false);
    var body = new URLSearchParams();
    body.set('action','wpcb_get_slots');
    body.set('nonce', (window.wpcbFrontend && wpcbFrontend.nonce) || '');
    body.set('type_id', typeId);
    var partySize = q('[name="party_size"]', form);
    body.set('party_size', partySize ? partySize.value || '1' : '1');
    var current = function(){
      return form.__wpcbSlots === state && state.context === slotContext(form);
    };
    fetch((window.wpcbFrontend && wpcbFrontend.ajaxUrl) || '/wp-admin/admin-ajax.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString(),
      signal: state.controller ? state.controller.signal : undefined
    }).then(function(response){
      return response.json().then(function(json){ return {ok: response.ok, json: json}; });
    }).then(function(result){
      if(!current()) return;
      state.pending = false;
      var json = result.json;
      if(result.ok === false || !json || !json.success){
        var error = json && json.data && typeof json.data.message === 'string'
          ? json.data.message.slice(0, 500) : msg('loadError','Error loading times');
        placeholder(select, msg('loadError','Error loading times'));
        slotStatus(form, error, true);
        updateSubmit(form);
        return;
      }
      var items = json.data && json.data.slots;
      if(!Array.isArray(items)) throw new Error('Invalid slot response');
      placeholder(select, items.length ? msg('choose','Please choose') : msg('noSlots','No available times found'));
      items.forEach(function(slot){
        if(!slot || typeof slot.value !== 'string' || typeof slot.label !== 'string') throw new Error('Invalid slot');
        var opt = document.createElement('option');
        opt.value = slot.value;
        opt.textContent = slot.label;
        if(preselect && preselect === slot.value) opt.selected = true;
        select.appendChild(opt);
      });
      select.disabled = !items.length;
      slotStatus(form, items.length ? '' : msg('noSlots','No available times found'), false);
      updateSubmit(form);
    }).catch(function(){
      if(!current()) return;
      state.pending = false;
      select.disabled = true;
      placeholder(select, msg('loadError','Error loading times'));
      slotStatus(form, msg('loadError','Error loading times'), true);
      updateSubmit(form);
    });
  }

  document.addEventListener('submit', function(e){
    var form = closest(e.target, '[data-wpcb-booking-form]');
    if(form && !updateSubmit(form)){
      e.preventDefault();
      var select = q('[data-wpcb-slot-select]', form);
      if(select) select.focus();
    }
  });

  document.addEventListener('input', function(e){
    var form = closest(e.target, '[data-wpcb-booking-form]');
    if(form && e.target.matches('[data-wpcb-party-size]')){
      var type = q('[data-wpcb-type-select]', form);
      fillSlots(form, type ? type.value : '', '');
    }
  });

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
    updateSubmit(form);
  });

  document.addEventListener('click', function(e){
    var retry = closest(e.target, '[data-wpcb-slot-retry]');
    if(retry){
      var retryForm = closest(retry, '[data-wpcb-booking-form]');
      var retryType = retryForm && q('[data-wpcb-type-select]', retryForm);
      if(retryType) fillSlots(retryForm, retryType.value, '');
      return;
    }
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
      var type = q('[data-wpcb-type-select]', form);
      if(type && type.value) fillSlots(form, type.value, '');
      else updateSubmit(form);
    });
  });
})();
