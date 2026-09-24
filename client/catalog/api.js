import apiFetch from '@wordpress/api-fetch';

const config = window.cachicamoAppCatalog || { restUrl: '', nonce: '' };

apiFetch.use( apiFetch.createRootURLMiddleware( config.restUrl ) );
apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

export default apiFetch;
