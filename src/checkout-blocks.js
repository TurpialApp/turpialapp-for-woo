import { __ } from '@wordpress/i18n';
import { addFilter } from '@wordpress/hooks';

const settings = window.wc.wcSettings.getSetting('cachicamoapp-custom-fields', {});

// Create custom filter hook for checkout
const FILTER_NAME = 'woocommerce_checkout_fields';

// Handler function to add custom fields
const addCustomFields = (fields) => {
    // Add DNI field
    if (settings.showDniField) {
        fields.billing.billing_dni = {
            label: settings.dniLabel,
            placeholder: settings.dniPlaceholder,
            required: true,
            priority: 120,
        };
    }
    
    // Add VAT field
    if (settings.showVatField) {
        fields.billing.billing_company_vat = {
            label: settings.vatLabel,
            placeholder: settings.vatPlaceholder,
            required: false,
            priority: 130,
        };
    }
    
    return fields;
};

// Add the filter
addFilter(FILTER_NAME, 'cachicamoapp/custom-checkout-fields', addCustomFields);

// Add custom validation if needed
const validateCustomFields = (data, errors) => {
    if (settings.showDniField && !data.billing_dni) {
        errors.add('validation', __('Please enter your ID number', 'cachicamoapp-for-woo'));
    }
    
    return errors;
};

addFilter('woocommerce_checkout_fields_validation', 'cachicamoapp/checkout-fields-validation', validateCustomFields);