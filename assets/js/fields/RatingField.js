function (root) {
    root.querySelectorAll('.fabricator-rating-group').forEach(function (group) {
        /* Idempotent: front.js re-runs field inits after every successful submit. */
        if (group._fabricatorRatingInited) return;
        group._fabricatorRatingInited = true;
        var stars = Array.from(group.querySelectorAll('.fabricator-rating-star'));
        function highlight(val) {
            stars.forEach(function (star) {
                var n    = parseInt(star.dataset.star, 10);
                var full = n <= Math.floor(val);
                var half = !full && val % 1 !== 0 && n === Math.ceil(val);
                star.classList.toggle('fabricator-rating-star--full', full);
                star.classList.toggle('fabricator-rating-star--half', half);
            });
        }
        function restoreChecked() {
            var checked = group.querySelector('input:checked');
            highlight(checked ? parseFloat(checked.value) : 0);
        }
        group.addEventListener('mouseover', function (e) {
            var zone = e.target.closest('.fabricator-rating-zone');
            if (!zone) return;
            var radio = zone.querySelector('input[type="radio"]');
            if (radio) highlight(parseFloat(radio.value));
        });
        group.addEventListener('mouseleave', restoreChecked);
        /* Arrow keys move between the radios natively, without a click, so the stars follow the change event. */
        group.addEventListener('change', restoreChecked);
        group.querySelectorAll('.fabricator-rating-zone').forEach(function (zone) {
            var radio = zone.querySelector('input[type="radio"]');
            if (!radio) return;
            zone.addEventListener('click', function () {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                restoreChecked();
            });
        });
        /* Dispatched by front.js after form.reset(), so the stars follow the cleared radios. */
        var ownerForm = group.closest('form');
        if (ownerForm) ownerForm.addEventListener('fabricator:reset', restoreChecked);
        restoreChecked();
    });
}
