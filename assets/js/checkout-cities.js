(function ($) {
    'use strict';

    function normalize(text) {
        if (!text) {
            return '';
        }

        return String(text)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toUpperCase();
    }

    function getCitiesByState(stateCode) {
        if (!window.mcws_city_params || !window.mcws_city_params.cities) {
            return {};
        }

        return window.mcws_city_params.cities[stateCode] || {};
    }

    function getPostalCode(stateCode, cityName) {
        if (!window.mcws_city_params || !window.mcws_city_params.postalCodes) {
            return '';
        }

        var byState = window.mcws_city_params.postalCodes[stateCode] || {};
        var key = normalize(cityName);

        if (byState[key]) {
            return byState[key];
        }

        var foundKey = Object.keys(byState).find(function (cityKey) {
            return cityKey.indexOf(key) >= 0 || key.indexOf(cityKey) >= 0;
        });

        return foundKey ? byState[foundKey] : '';
    }

    function setPostcode(scopePrefix) {
        var $state = $('select#' + scopePrefix + '_state');
        var $city = $('select#' + scopePrefix + '_city');
        var $postcode = $('input#' + scopePrefix + '_postcode');

        if (!$state.length || !$city.length || !$postcode.length) {
            return;
        }

        var postcode = getPostalCode($state.val(), $city.val());
        if (!postcode) {
            return;
        }

        $postcode.val(postcode).trigger('change');
    }

    function renderCityOptions($citySelect, stateCode) {
        if (!$citySelect.length) {
            return;
        }

        var cities = getCitiesByState(stateCode);
        var placeholder = (window.mcws_city_params && window.mcws_city_params.placeholder)
            ? window.mcws_city_params.placeholder
            : 'Select a commune...';

        var options = '<option value="">' + placeholder + '</option>';

        Object.keys(cities).forEach(function (key) {
            var value = cities[key];
            var label;
            if (Array.isArray(value)) {
                label = value.name || value[0] || '';
            } else if (value && typeof value === 'object') {
                label = value.name || '';
            } else {
                label = value || '';
            }
            options += '<option value="' + label + '">' + label + '</option>';
        });

        $citySelect.html(options).trigger('change.select2');
    }

    function bind(scopePrefix) {
        var $state = $('select#' + scopePrefix + '_state');
        var $city = $('select#' + scopePrefix + '_city');

        if (!$state.length || !$city.length) {
            return;
        }

        $state.off('change.mcws').on('change.mcws', function () {
            renderCityOptions($city, $(this).val());
            setPostcode(scopePrefix);
            $('body').trigger('update_checkout');
        });

        $city.off('change.mcws').on('change.mcws', function () {
            setPostcode(scopePrefix);
            $('body').trigger('update_checkout');
        });

        if ($state.val()) {
            renderCityOptions($city, $state.val());
            setPostcode(scopePrefix);
        }

        if ($city.data('select2')) {
            $city.select2({
                width: '100%',
                minimumInputLength: 0
            });
        }
    }

    $(document.body).on('updated_checkout', function () {
        bind('billing');
        bind('shipping');
    });

    $(function () {
        bind('billing');
        bind('shipping');
    });
}(jQuery));
