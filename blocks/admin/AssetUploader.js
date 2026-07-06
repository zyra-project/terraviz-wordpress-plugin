/**
 * Asset upload panel for the dataset form. Drives the presigned-R2 flow in
 * `upload.js`; only shown when editing an existing dataset (an id is needed to
 * mint the upload).
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Notice, Spinner, SelectControl } from '@wordpress/components';
import { uploadAsset } from './upload';
import { normalizeError } from './api';

const KINDS = [
	{ value: 'data', label: __( 'Data (the globe layer)', 'terraviz' ) },
	{ value: 'thumbnail', label: __( 'Thumbnail', 'terraviz' ) },
	{ value: 'legend', label: __( 'Legend', 'terraviz' ) },
	{ value: 'caption', label: __( 'Caption (VTT)', 'terraviz' ) },
	{ value: 'sphere_thumbnail', label: __( 'Sphere thumbnail', 'terraviz' ) },
];

const STAGE_LABELS = {
	hashing: __( 'Hashing file…', 'terraviz' ),
	initiating: __( 'Requesting upload…', 'terraviz' ),
	uploading: __( 'Uploading…', 'terraviz' ),
	finalizing: __( 'Finalizing…', 'terraviz' ),
};

export default function AssetUploader( { id, onUploaded } ) {
	const [ kind, setKind ] = useState( 'data' );
	const [ file, setFile ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ stage, setStage ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const handleUpload = () => {
		if ( ! file ) {
			return;
		}
		setBusy( true );
		setNotice( null );
		uploadAsset( { id, kind, file, onStage: setStage } )
			.then( ( res ) => {
				const transcoding = res && res.transcoding;
				setNotice( {
					type: transcoding ? 'info' : 'success',
					text: transcoding
						? __(
								'Uploaded. The video is transcoding on the node and will be ready shortly.',
								'terraviz'
						  )
						: __( 'Uploaded and applied.', 'terraviz' ),
				} );
				setFile( null );
				if ( onUploaded ) {
					onUploaded( res && res.dataset );
				}
			} )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => {
				setBusy( false );
				setStage( null );
			} );
	};

	return (
		<fieldset
			style={ {
				border: '1px solid #ddd',
				padding: '8px 12px',
				margin: '12px 0',
			} }
		>
			<legend>{ __( 'Upload asset', 'terraviz' ) }</legend>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<SelectControl
				label={ __( 'Kind', 'terraviz' ) }
				value={ kind }
				options={ KINDS }
				onChange={ setKind }
				__nextHasNoMarginBottom
			/>
			<p>
				<input
					type="file"
					disabled={ busy }
					onChange={ ( e ) =>
						setFile(
							e.target.files && e.target.files[ 0 ]
								? e.target.files[ 0 ]
								: null
						)
					}
				/>
			</p>
			<Button
				variant="secondary"
				onClick={ handleUpload }
				isBusy={ busy }
				disabled={ busy || ! file }
			>
				{ __( 'Upload', 'terraviz' ) }
			</Button>
			{ busy && stage && (
				<span style={ { marginLeft: '8px' } }>
					<Spinner /> { STAGE_LABELS[ stage ] || '' }
				</span>
			) }
			<p className="components-base-control__help">
				{ __(
					'The file is hashed in your browser and uploaded directly to the node’s storage; the data reference is set automatically. Very large videos may take a while to hash.',
					'terraviz'
				) }
			</p>
		</fieldset>
	);
}
