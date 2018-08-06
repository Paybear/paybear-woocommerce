(function () {
    var container = document.getElementById('paybear');
    if (!container) {
        return;
    }
    window.paybearWidget = new Paybear({
        button: '#paybear-all',
        fiatValue: parseFloat(container.getAttribute('data-fiat-value')),
        currencies: JSON.parse(htmlDecode(container.getAttribute('data-currencies'))),
        statusUrl: container.getAttribute('data-status'),
        redirectTo: container.getAttribute('data-redirect'),
        fiatCurrency: container.getAttribute('data-fiat-currency'),
        fiatSign: container.getAttribute('data-fiat-sign'),
        minOverpaymentFiat: container.getAttribute('data-min-overpayment-fiat'),
        maxUnderpaymentFiat: container.getAttribute('data-max-underpayment-fiat'),
        modal: true,
        enablePoweredBy: true,
        redirectPendingTo: container.getAttribute('data-redirect'),
        timer: container.getAttribute('data-rate_lock_time')
    });

    function htmlDecode(input){
        var e = document.createElement('div');
        e.innerHTML = input;
        // handle case of empty input
        return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
    }

    var autoopen = container.getAttribute('data-autoopen');

    if (autoopen && autoopen == 'true') {
        document.getElementById('paybear-all').click();
    }

})();
