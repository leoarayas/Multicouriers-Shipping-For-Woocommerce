(function ($) {
    'use strict';

    function stateOptions(selectedValue) {
        var html = '<option value="">Select region</option>';
        var states = (window.mcwsAdminRates && window.mcwsAdminRates.states) ? window.mcwsAdminRates.states : {};

        Object.keys(states).forEach(function (code) {
            var selected = code === selectedValue ? ' selected="selected"' : '';
            html += '<option value="' + code + '"' + selected + '>' + code + ' - ' + states[code] + '</option>';
        });

        return html;
    }

    function getCitiesByRegion(regionCode) {
        var cities = (window.mcwsAdminRates && window.mcwsAdminRates.cities) ? window.mcwsAdminRates.cities : {};
        if (!regionCode || !cities[regionCode]) {
            return [];
        }

        return Object.keys(cities[regionCode]).map(function (key) {
            var value = cities[regionCode][key];
            return Array.isArray(value) ? value[0] : value;
        }).filter(function (value) {
            return !!value;
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function communeOptions(regionCode, selectedValue) {
        var html = '<option value="">Select commune</option>';
        getCitiesByRegion(regionCode).forEach(function (city) {
            var selected = city === selectedValue ? ' selected="selected"' : '';
            html += '<option value="' + escapeHtml(city) + '"' + selected + '>' + escapeHtml(city) + '</option>';
        });
        return html;
    }

    function refreshRow($row) {
        var region = $row.find('.mcws-region').val() || '';
        var mode = $row.find('.mcws-commune-mode').val() || 'only';
        var $communes = $row.find('.mcws-communes');
        var $csv = $row.find('.mcws-communes-csv');
        var selectedCsv = $communes.data('selectedCsv') || $csv.val() || '';
        var selected = selectedCsv ? selectedCsv.split(',').map(function (v) { return v.trim(); }).filter(Boolean) : [];

        $communes.html(communeOptions(region, ''));
        selected.forEach(function (city) {
            var exists = $communes.find('option').filter(function () {
                return $(this).val() === city;
            }).length > 0;
            if (!exists && city) {
                $communes.append('<option value="' + escapeHtml(city) + '">' + escapeHtml(city) + '</option>');
            }
        });
        $communes.val(selected);
        $communes.data('selectedCsv', '');
        $communes.trigger('change');

        if (mode === 'all') {
            $communes.val([]);
            $communes.prop('disabled', true);
            $csv.val('');
        } else {
            $communes.prop('disabled', false);
            syncCsv($row);
        }
    }

    function newRow() {
        return '' +
            '<tr class="mcws-rate-row">' +
                '<td><select name="mcws_region[]" class="mcws-region">' + stateOptions('') + '</select></td>' +
                '<td>' +
                    '<select name="mcws_commune_mode[]" class="mcws-commune-mode">' +
                        '<option value="all">All</option>' +
                        '<option value="only" selected="selected">Only</option>' +
                        '<option value="exclude">Excluding</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select class="mcws-communes wc-enhanced-select" multiple="multiple"><option value="">Select commune</option></select>' +
                    '<input type="hidden" name="mcws_communes_csv[]" class="mcws-communes-csv" value="" />' +
                '</td>' +
                '<td><input type="number" min="0" step="1" name="mcws_cost[]" value="" /></td>' +
                '<td><button class="button-link-delete mcws-remove-row" type="button">Delete</button></td>' +
            '</tr>';
    }

    function syncCsv($row) {
        var $communes = $row.find('.mcws-communes');
        var selected = ($communes.val() || []).filter(function (value) { return !!value; });
        $row.find('.mcws-communes-csv').val(selected.join(','));
    }

    function initEnhanced($scope) {
        if (!$.fn.selectWoo && !$.fn.select2) {
            return;
        }
        var initFn = $.fn.selectWoo ? 'selectWoo' : 'select2';
        $scope.find('.mcws-communes').each(function () {
            var $el = $(this);
            if ($el.data('select2') || $el.data('selectWoo')) {
                return;
            }
            $el[initFn]({
                width: '100%',
                placeholder: 'Select communes',
                allowClear: true
            });
        });
    }

    $(function () {
        $('#mcws-add-row').on('click', function () {
            var $row = $(newRow());
            $('#mcws-fixed-rates-table tbody .mcws-empty-row').remove();
            $('#mcws-fixed-rates-table tbody').append($row);
            refreshRow($row);
            initEnhanced($row);
        });

        $(document).on('click', '.mcws-remove-row', function () {
            $(this).closest('tr').remove();
            if ($('#mcws-fixed-rates-table tbody .mcws-rate-row').length === 0) {
                $('#mcws-fixed-rates-table tbody').append(
                    '<tr class="mcws-empty-row"><td colspan="5">No saved rules. Add a row to start.</td></tr>'
                );
            }
        });

        $(document).on('change', '.mcws-region, .mcws-commune-mode', function () {
            refreshRow($(this).closest('tr'));
        });

        $(document).on('change', '.mcws-communes', function () {
            syncCsv($(this).closest('tr'));
        });

        $('#mcws-fixed-rates-table').closest('form').on('submit', function () {
            $('#mcws-fixed-rates-table tbody .mcws-rate-row').each(function () {
                syncCsv($(this));
            });
        });

        $('#mcws-fixed-rates-table tbody .mcws-rate-row').each(function () {
            var $row = $(this);
            refreshRow($row);
            initEnhanced($row);
        });
    });
}(jQuery));
