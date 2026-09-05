function (root) {
    root.querySelectorAll('input[value="__other__"]').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var wrap  = this.closest('.fabricator-radio-group, .fabricator-checkbox-group');
            var other = wrap && wrap.querySelector('.fabricator-other-input');
            if (!other) return;
            other.style.display = this.checked ? '' : 'none';
        });
    });
}