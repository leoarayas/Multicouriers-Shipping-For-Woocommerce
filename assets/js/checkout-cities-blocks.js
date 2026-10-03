(function () {
    'use strict';

    function getParams() {
        return window.mcws_city_params || { cities: {}, postalCodes: {}, placeholder: 'Select a commune...' };
    }

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

    function getCitiesForState(stateCode) {
        var params = getParams();
        if (!params.cities || !stateCode || !params.cities[stateCode]) {
            return [];
        }

        var regionCities = params.cities[stateCode];
        var list = [];

        Object.keys(regionCities).forEach(function (key) {
            var value = regionCities[key];
            var name;
            if (Array.isArray(value)) {
                name = value.name || value[0] || '';
            } else if (value && typeof value === 'object') {
                name = value.name || '';
            } else {
                name = value || '';
            }
            if (typeof name === 'string' && name.length > 0) {
                list.push(name);
            }
        });

        return list;
    }

    function getPostalCode(stateCode, cityName) {
        var params = getParams();
        if (!params.postalCodes || !params.postalCodes[stateCode]) {
            return '';
        }

        var map = params.postalCodes[stateCode];
        var key = normalize(cityName);
        if (map[key]) {
            return map[key];
        }

        var fallbackKey = Object.keys(map).find(function (cityKey) {
            return cityKey.indexOf(key) >= 0 || key.indexOf(cityKey) >= 0;
        });

        return fallbackKey ? map[fallbackKey] : '';
    }

    function findField(scope, field, inputTag) {
        var byIdDashed = document.querySelector(inputTag + '#' + scope + '-' + field);
        if (byIdDashed) {
            return byIdDashed;
        }

        var byIdUnderscore = document.querySelector(inputTag + '#' + scope + '_' + field);
        if (byIdUnderscore) {
            return byIdUnderscore;
        }

        var byNameDashed = document.querySelector(inputTag + '[name="' + scope + '-' + field + '"]');
        if (byNameDashed) {
            return byNameDashed;
        }

        var byNameUnderscore = document.querySelector(inputTag + '[name="' + scope + '_' + field + '"]');
        if (byNameUnderscore) {
            return byNameUnderscore;
        }

        return document.querySelector(inputTag + '[name="' + scope + '[' + field + ']"]');
    }

    function getPlaceholder() {
        var params = getParams();
        return params.placeholder || 'Select a commune...';
    }

    function getRegionPlaceholder() {
        return 'Select a region first...';
    }

    function findCityInput(scope) {
        return findField(scope, 'city', 'input');
    }

    function findCitySelect(scope) {
        return document.getElementById('mcws-' + scope + '-city-select');
    }

    function setNativeInputValue(input, value) {
        if (!input) {
            return false;
        }

        var nextValue = String(value || '');
        var currentValue = String(input.value || '');
        if (currentValue === nextValue) {
            return false;
        }

        var prototype = Object.getPrototypeOf(input);
        var descriptor = prototype ? Object.getOwnPropertyDescriptor(prototype, 'value') : null;
        var setter = descriptor && typeof descriptor.set === 'function' ? descriptor.set : null;

        if (setter) {
            setter.call(input, nextValue);
        } else {
            input.value = nextValue;
        }

        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('blur', { bubbles: true }));

        return true;
    }

    function hideNativeCityInput(cityInput) {
        if (!cityInput) {
            return;
        }

        cityInput.style.display = 'none';
        cityInput.setAttribute('aria-hidden', 'true');
        cityInput.setAttribute('data-mcws-city-native-hidden', '1');
    }

    function showNativeCityInput(cityInput) {
        var citySelect = cityInput ? findCitySelect(cityInput.dataset.mcwsScope || '') : null;

        if (citySelect) {
            citySelect.style.display = 'none';
            citySelect.disabled = true;
        }

        if (!cityInput) {
            return;
        }

        cityInput.style.display = '';
        cityInput.removeAttribute('aria-hidden');
        cityInput.removeAttribute('data-mcws-city-native-hidden');
    }

    function ensureCitySelect(scope) {
        var cityInput = findCityInput(scope);
        if (!cityInput) {
            return null;
        }

        cityInput.dataset.mcwsScope = scope;

        var existing = findCitySelect(scope);
        if (existing && existing.dataset.mcwsNativeInputId === (cityInput.id || '')) {
            return existing;
        }

        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var select = document.createElement('select');
        select.id = 'mcws-' + scope + '-city-select';
        select.className = cityInput.className || '';
        select.dataset.mcwsScope = scope;
        select.dataset.mcwsNativeInputId = cityInput.id || '';
        select.style.width = '100%';

        cityInput.parentNode.insertBefore(select, cityInput.nextSibling);
        hideNativeCityInput(cityInput);

        return select;
    }

    function renderCitySelectOptions(select, cities, selectedValue, disabledPlaceholder) {
        if (!select) {
            return;
        }

        var signature = [disabledPlaceholder ? '1' : '0', selectedValue || '', cities.join('|')].join('::');
        if (select.dataset.mcwsOptionsSignature === signature && !select.dataset.mcwsForceRender) {
            return;
        }

        // Store current value before clearing
        var currentValue = select.value || select.dataset.mcwsLastValue || '';

        select.innerHTML = '';

        var placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = disabledPlaceholder ? getRegionPlaceholder() : getPlaceholder();
        select.appendChild(placeholderOption);

        cities.forEach(function (city) {
            var option = document.createElement('option');
            option.value = city;
            option.textContent = city;
            select.appendChild(option);
        });

        // Try to restore: prefer selectedValue > currentValue > placeholder
        if (selectedValue && cities.indexOf(selectedValue) >= 0) {
            select.value = selectedValue;
        } else if (currentValue && cities.indexOf(currentValue) >= 0) {
            select.value = currentValue;
        } else {
            select.value = '';
        }

        // Remember this value for future renders
        select.dataset.mcwsLastValue = select.value;
        select.dataset.mcwsOptionsSignature = signature;
        select.disabled = !!disabledPlaceholder;
        select.style.display = '';
        select.removeAttribute('data-mcws-force-render');
    }

    function setPostcode(scope) {
        var cityInput = findCityInput(scope);
        var citySelect = findCitySelect(scope);
        var stateField = findField(scope, 'state', 'select');
        var postcodeInput = findField(scope, 'postcode', 'input');

        if (!cityInput || !stateField || !postcodeInput) {
            return;
        }

        var cityValue = citySelect && citySelect.style.display !== 'none' ? citySelect.value : cityInput.value;
        var postcode = getPostalCode(stateField.value, cityValue);
        if (!postcode) {
            return;
        }

        setNativeInputValue(postcodeInput, postcode);
    }

    function updateScope(scope) {
        var cityInput = findCityInput(scope);
        var stateField = findField(scope, 'state', 'select');
        var countryField = findField(scope, 'country', 'select');

        if (!cityInput || !stateField) {
            return;
        }

        var countryCode = countryField ? String(countryField.value || '').toUpperCase() : 'CL';
        if (countryCode && countryCode !== 'CL') {
            showNativeCityInput(cityInput);
            return;
        }

        var citySelect = ensureCitySelect(scope);
        if (!citySelect) {
            return;
        }

        var lastValue = citySelect.dataset.mcwsLastValue || '';

        hideNativeCityInput(cityInput);

        var stateCode = String(stateField.value || '');
        var cities = stateCode ? getCitiesForState(stateCode) : [];
        var currentValue = citySelect.value || cityInput.value || lastValue;
        renderCitySelectOptions(citySelect, cities, currentValue, !stateCode);

        setPostcode(scope);
    }

    function bindEvents(scope) {
        var stateField = findField(scope, 'state', 'select');
        var countryField = findField(scope, 'country', 'select');
        var cityInput = findCityInput(scope);
        var citySelect = findCitySelect(scope);

        if (stateField && !stateField.dataset.mcwsBound) {
            stateField.addEventListener('change', function () {
                updateScope(scope);
            });
            stateField.dataset.mcwsBound = '1';
        }

        if (countryField && !countryField.dataset.mcwsBound) {
            countryField.addEventListener('change', function () {
                updateScope(scope);
            });
            countryField.dataset.mcwsBound = '1';
        }

        if (cityInput && !cityInput.dataset.mcwsBound) {
            cityInput.addEventListener('change', function () {
                updateScope(scope);
                setPostcode(scope);
            });
            cityInput.addEventListener('blur', function () {
                updateScope(scope);
                setPostcode(scope);
            });
            cityInput.dataset.mcwsBound = '1';
        }

        if (citySelect && !citySelect.dataset.mcwsBound) {
            citySelect.addEventListener('change', function () {
                var nativeInput = findCityInput(scope);
                if (!nativeInput) {
                    return;
                }

                // Remember selection for persistence
                citySelect.dataset.mcwsLastValue = citySelect.value || '';

                if (String(nativeInput.value || '') === String(citySelect.value || '')) {
                    setPostcode(scope);
                    return;
                }

                setNativeInputValue(nativeInput, citySelect.value || '');
                setPostcode(scope);
            });
            citySelect.dataset.mcwsBound = '1';
        }
    }

    function run() {
        ['billing', 'shipping'].forEach(function (scope) {
            updateScope(scope);
            bindEvents(scope);
        });
    }

    var scheduled = false;

    function scheduleRun() {
        if (scheduled) {
            return;
        }

        scheduled = true;
        window.requestAnimationFrame(function () {
            scheduled = false;
            run();
        });
    }

    var observer = new MutationObserver(function (mutations) {
        var hasRelevantChange = mutations.some(function (mutation) {
            var nodes = Array.prototype.slice.call(mutation.addedNodes || []).concat(Array.prototype.slice.call(mutation.removedNodes || []));
            if (!nodes.length) {
                return false;
            }

            return nodes.some(function (node) {
                if (!node || node.nodeType !== 1) {
                    return false;
                }

                if (node.id && typeof node.id === 'string' && node.id.indexOf('mcws-') === 0 && node.tagName === 'DATALIST') {
                    return false;
                }

                return true;
            });
        });

        if (hasRelevantChange) {
            scheduleRun();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        run();
        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
