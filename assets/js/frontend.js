(function(){
  function closest(el, sel){ return el && el.closest ? el.closest(sel) : null; }
  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function isPhoneType(text){ text=(text||'').toLowerCase(); return text.indexOf('telefon')!==-1; }
  function isVisitType(text){ text=(text||'').toLowerCase(); return text.indexOf('besuch')!==-1 || text.indexOf('vor ort')!==-1; }

  function updateConditional(form){
    if(!form) return;
    var typeSelect = q('[data-cemb-type-select]', form);
    var typeText = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex].text : '';
    var whoCalls = q('[name="who_calls"]', form);
    var phoneField = closest(q('[name="phone"]', form), '.cemb-field');
    var locationField = closest(q('[name="location"]', form), '.cemb-field');
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
      var wrapper = closest(whoCalls, '.cemb-field');
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

  function showModal(modal){
    if(window.UIkit && UIkit.modal){
      modal.removeAttribute('hidden');
      UIkit.modal(modal).show();
      return;
    }
    modal.hidden = false;
    document.documentElement.classList.add('cemb-modal-open');
  }

  function hideModal(modal){
    if(window.UIkit && UIkit.modal){
      var inst = UIkit.modal(modal);
      inst.hide();
      return;
    }
    modal.hidden = true;
    document.documentElement.classList.remove('cemb-modal-open');
  }

  function fillSlots(form, typeId, preselect){
    var select = q('[data-cemb-slot-select]', form);
    if(!select) return;
    select.innerHTML = '<option value="">Lade freie Zeiten ...</option>';
    var body = new URLSearchParams();
    body.set('action','cemb_get_slots');
    body.set('nonce', (window.cembFrontend && cembFrontend.nonce) || '');
    body.set('type_id', typeId || '');
    fetch((window.cembFrontend && cembFrontend.ajaxUrl) || '/wp-admin/admin-ajax.php', {
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
    var form = closest(e.target, '[data-cemb-booking-form]');
    if(!form) return;
    if(e.target.matches('[data-cemb-type-select]')){
      fillSlots(form, e.target.value, '');
    }
    updateConditional(form);
  });

  document.addEventListener('click', function(e){
    var open = closest(e.target, '[data-cemb-open-toolbar-modal]');
    if(open){
      e.preventDefault();
      var wrap = closest(open, '[data-cemb-booking-calendar]') || document;
      var modal = q('[data-cemb-modal]', wrap);
      if(!modal) return;
      showModal(modal);
      return;
    }
    var close = closest(e.target, '[data-cemb-close-modal]');
    if(close){
      e.preventDefault();
      var modal2 = closest(close, '[data-cemb-modal]');
      if(modal2) hideModal(modal2);
    }
  });

  document.addEventListener('DOMContentLoaded', function(){
    qa('[data-cemb-booking-form]').forEach(function(form){
      updateConditional(form);
    });
  });
})();
