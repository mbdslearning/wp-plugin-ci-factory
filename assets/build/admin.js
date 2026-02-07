(function () {
  function setStatus(el, msg) {
    if (!el) return;
    el.textContent = msg || '';
    if (msg) {
      window.setTimeout(function () { el.textContent = ''; }, 1500);
    }
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'absolute';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        ok ? resolve() : reject();
      } catch (e) {
        reject(e);
      }
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('[data-wc-paymongo-copy]') : null;
    if (!btn) return;

    var container = btn.closest('[data-wc-paymongo-webhook]');
    if (!container) return;

    var input = container.querySelector('#wc-paymongo-webhook-url');
    var status = container.querySelector('[data-wc-paymongo-copy-status]');
    if (!input) return;

    var text = input.value || '';
    if (!text) return;

    copyText(text).then(function () {
      setStatus(status, (window.WCPayMongoCheckoutAdmin && WCPayMongoCheckoutAdmin.copied) ? WCPayMongoCheckoutAdmin.copied : 'Copied');
    }).catch(function () {
      setStatus(status, (window.WCPayMongoCheckoutAdmin && WCPayMongoCheckoutAdmin.copyFailed) ? WCPayMongoCheckoutAdmin.copyFailed : 'Copy failed');
    });
  });
})();