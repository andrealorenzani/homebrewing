/**
 * Small, unobtrusive progressive enhancement for the recipe create/edit
 * form (templates/pages/recipes/form.php). No framework, no build step.
 *
 * Two independent behaviors, both optional — the form is fully
 * submittable and usable without this file:
 *
 *   1. "Add ingredient" button: clones a blank ingredient row from the
 *      <template id="ingredient-row-template"> and appends it to
 *      #ingredient-rows, with id/for attributes re-indexed so each row
 *      stays uniquely addressable. The ingredient_*[] `name` attributes
 *      are left as-is (they're arrays; the server pairs fields by their
 *      position within each array, not by a shared index), so no
 *      re-indexing is needed there for correct submission.
 *
 *   2. Live estimated-ABV output: on `input` events on the OG/FG fields,
 *      computes and displays (OG - FG) * 131.25 when FG has a value,
 *      otherwise (OG - 1.000) * 131.25 (potential ABV assuming full
 *      attenuation), formatted to one decimal place.
 */
(function () {
    'use strict';

    function initAddIngredientRow() {
        var container = document.getElementById('ingredient-rows');
        var template = document.getElementById('ingredient-row-template');
        var button = document.getElementById('add-ingredient-row');

        if (!container || !template || !button || typeof template.content === 'undefined') {
            return;
        }

        button.addEventListener('click', function () {
            var index = container.querySelectorAll('.ingredient-fieldset').length;
            var clone = template.content.cloneNode(true);
            var fieldset = clone.querySelector('.ingredient-fieldset');

            if (!fieldset) {
                return;
            }

            var idElements = fieldset.querySelectorAll('[id]');
            for (var i = 0; i < idElements.length; i++) {
                idElements[i].id = idElements[i].id.replace('__INDEX__', String(index));
            }

            var labelElements = fieldset.querySelectorAll('label[for]');
            for (var j = 0; j < labelElements.length; j++) {
                var forAttr = labelElements[j].getAttribute('for');
                labelElements[j].setAttribute('for', forAttr.replace('__INDEX__', String(index)));
            }

            var legend = fieldset.querySelector('legend');
            if (legend) {
                legend.textContent = 'Ingredient ' + (index + 1);
            }

            container.appendChild(clone);
        });
    }

    function initAbvEstimate() {
        var ogInput = document.getElementById('target_og');
        var fgInput = document.getElementById('target_fg');
        var output = document.getElementById('abv-estimate');

        if (!ogInput || !fgInput || !output) {
            return;
        }

        function updateEstimate() {
            var og = parseFloat(ogInput.value);

            if (isNaN(og)) {
                output.textContent = String.fromCharCode(0x2014); // em dash
                return;
            }

            var fgRaw = fgInput.value;
            var fg = parseFloat(fgRaw);
            var target = fgRaw !== '' && !isNaN(fg) ? fg : 1.0;
            var abv = (og - target) * 131.25;

            output.textContent = abv.toFixed(1) + '%';
        }

        ogInput.addEventListener('input', updateEstimate);
        fgInput.addEventListener('input', updateEstimate);
        updateEstimate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAddIngredientRow();
            initAbvEstimate();
        });
    } else {
        initAddIngredientRow();
        initAbvEstimate();
    }
})();
