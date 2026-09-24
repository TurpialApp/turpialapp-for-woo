import { __ } from '@wordpress/i18n';
import Flow from './flow';

export default function App() {
	return (
		<div className="cachicamoapp-catalog">
			<h1>{ __( 'Catalog', 'cachicamoapp-for-woo' ) }</h1>
			<Flow
				title={ __( 'Export: categories', 'cachicamoapp-for-woo' ) }
				previewSlug="export_categories"
				runSlug="export_categories"
			/>
			<Flow
				title={ __( 'Export: products', 'cachicamoapp-for-woo' ) }
				previewSlug="export_products"
				runSlug="export_products"
			/>
			<Flow
				title={ __( 'Export: attributes', 'cachicamoapp-for-woo' ) }
				previewSlug="export_attributes"
			/>
		</div>
	);
}
