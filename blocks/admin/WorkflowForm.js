/**
 * Create / edit form for a refresh workflow. Metadata + cadence + target dataset
 * live in native controls; the pipeline and the metadata template are opaque JSON
 * the node validates deeply, so they're monospace textareas with a client-side
 * JSON pre-check and a "Validate" button that dry-runs against the node's
 * allowlist. In edit mode a run-history panel with "Run now" is shown.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import {
	getWorkflow,
	createWorkflow,
	updateWorkflow,
	validateWorkflow,
	listDatasets,
	normalizeError,
} from './api';
import { SCHEDULE_PRESETS } from './schedule';
import WorkflowRuns from './WorkflowRuns';

const PIPELINE_SCAFFOLD = `{
  "stages": [
    {
      "stage": "fetch",
      "command": "fetch.http",
      "args": { "url": "https://example.org/data.json" }
    }
  ]
}`;

const TEMPLATE_SCAFFOLD = `{
  "title": "Refreshed dataset",
  "keywords": ["example"]
}`;

const BLANK = {
	name: '',
	description: '',
	target_dataset_id: '',
	schedule: 'P1D',
	enabled: false,
	pipeline_json: PIPELINE_SCAFFOLD,
	metadata_template: TEMPLATE_SCAFFOLD,
};

/**
 * Parse a JSON string, returning `{ ok, error }` (the error message on failure).
 *
 * @param {string} text JSON text.
 * @return {{ok: boolean, error: ?string}} Parse result.
 */
function tryParse( text ) {
	try {
		JSON.parse( text );
		return { ok: true, error: null };
	} catch ( e ) {
		return { ok: false, error: e.message };
	}
}

/**
 * Pretty-print JSON with 2-space indentation for the editor; unparseable text is
 * returned unchanged so a work-in-progress edit isn't clobbered.
 *
 * @param {string} text JSON text.
 * @return {string} Indented JSON, or the original text.
 */
function pretty( text ) {
	try {
		return JSON.stringify( JSON.parse( text ), null, 2 );
	} catch {
		return text;
	}
}

export default function WorkflowForm( { id, onSaved, onCancel } ) {
	const isEdit = Boolean( id );
	const [ values, setValues ] = useState( BLANK );
	const [ datasets, setDatasets ] = useState( null );
	const [ loading, setLoading ] = useState( isEdit );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	// The target-dataset picker is populated from the caller's own catalog.
	useEffect( () => {
		let active = true;
		listDatasets()
			.then(
				( res ) =>
					active &&
					setDatasets(
						Array.isArray( res.datasets ) ? res.datasets : []
					)
			)
			.catch( () => active && setDatasets( [] ) );
		return () => {
			active = false;
		};
	}, [] );

	useEffect( () => {
		let active = true;
		if ( ! isEdit ) {
			return undefined;
		}
		setLoading( true );
		getWorkflow( id )
			.then( ( res ) => {
				if ( ! active ) {
					return;
				}
				const w = res.workflow || res;
				setValues( {
					name: w.name || '',
					description: w.description || '',
					target_dataset_id: w.target_dataset_id || '',
					schedule: w.schedule || 'P1D',
					enabled: !! w.enabled,
					// The node stores minified JSON; indent it for the editor.
					pipeline_json: pretty( w.pipeline_json || '' ),
					metadata_template: pretty( w.metadata_template || '' ),
				} );
			} )
			.catch(
				( e ) =>
					active &&
					setNotice( {
						type: 'error',
						text: normalizeError( e ).message,
					} )
			)
			.finally( () => active && setLoading( false ) );
		return () => {
			active = false;
		};
	}, [ id, isEdit ] );

	const set = ( key ) => ( value ) =>
		setValues( ( v ) => ( { ...v, [ key ]: value } ) );

	const fieldError = ( name ) => {
		const hit = errors.find( ( e ) => e.field === name );
		return hit ? hit.message : null;
	};

	// The body the node expects: description as null when cleared; the two JSON
	// fields forwarded verbatim (the node is the deep validator).
	const toBody = useCallback(
		() => ( {
			name: values.name,
			description:
				values.description.trim() === '' ? null : values.description,
			target_dataset_id: values.target_dataset_id,
			schedule: values.schedule,
			enabled: values.enabled,
			pipeline_json: values.pipeline_json,
			metadata_template: values.metadata_template,
		} ),
		[ values ]
	);

	// Catch obvious JSON mistakes before the round-trip; the node still
	// re-validates shape/allowlist. Returns true when both parse.
	const jsonOk = () => {
		const local = [];
		const p = tryParse( values.pipeline_json );
		if ( ! p.ok ) {
			local.push( { field: 'pipeline_json', message: p.error } );
		}
		const t = tryParse( values.metadata_template );
		if ( ! t.ok ) {
			local.push( { field: 'metadata_template', message: t.error } );
		}
		if ( local.length ) {
			setErrors( local );
			setNotice( {
				type: 'error',
				text: __(
					'Fix the JSON in the highlighted fields.',
					'terraviz'
				),
			} );
			return false;
		}
		return true;
	};

	const handleSave = () => {
		setErrors( [] );
		setNotice( null );
		if ( ! jsonOk() ) {
			return;
		}
		setSaving( true );
		const body = toBody();
		const req = isEdit
			? updateWorkflow( id, body )
			: createWorkflow( body );
		req.then( ( res ) => {
			const w = res.workflow || res;
			setNotice( { type: 'success', text: __( 'Saved.', 'terraviz' ) } );
			onSaved( w );
		} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( {
					type: 'error',
					text:
						n.message ||
						__(
							'Could not save. Check the highlighted fields.',
							'terraviz'
						),
				} );
			} )
			.finally( () => setSaving( false ) );
	};

	const handleValidate = () => {
		setErrors( [] );
		setNotice( null );
		if ( ! jsonOk() ) {
			return;
		}
		setSaving( true );
		// The validate route needs an id path segment but never reads the row, so
		// a placeholder works before the workflow exists (create mode).
		validateWorkflow( id || 'new', toBody() )
			.then( ( res ) => {
				if ( res && res.ok ) {
					setNotice( {
						type: 'success',
						text: __(
							'Looks good — this workflow passes validation.',
							'terraviz'
						),
					} );
				} else {
					setErrors( ( res && res.errors ) || [] );
					setNotice( {
						type: 'warning',
						text: __(
							'Validation found problems — see the highlighted fields.',
							'terraviz'
						),
					} );
				}
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				setErrors( n.errors );
				setNotice( { type: 'error', text: n.message } );
			} )
			.finally( () => setSaving( false ) );
	};

	// Re-indent both JSON fields in place. Won't touch a field that doesn't parse
	// — jsonOk() surfaces which one so the user can fix it first.
	const handleFormat = () => {
		setErrors( [] );
		setNotice( null );
		if ( ! jsonOk() ) {
			return;
		}
		setValues( ( v ) => ( {
			...v,
			pipeline_json: pretty( v.pipeline_json ),
			metadata_template: pretty( v.metadata_template ),
		} ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	// A stored custom cadence (set outside the dashboard) is kept selectable.
	const scheduleOptions = SCHEDULE_PRESETS.some(
		( p ) => p.value === values.schedule
	)
		? SCHEDULE_PRESETS
		: [
				{ value: values.schedule, label: values.schedule },
				...SCHEDULE_PRESETS,
		  ];

	const datasetOptions = [
		{ value: '', label: __( '— Select a dataset —', 'terraviz' ) },
		...( datasets || [] ).map( ( d ) => ( {
			value: d.id,
			label: d.title || d.slug || d.id,
		} ) ),
	];
	// If editing and the stored target isn't in the caller's list, keep it.
	if (
		values.target_dataset_id &&
		! datasetOptions.some( ( o ) => o.value === values.target_dataset_id )
	) {
		datasetOptions.push( {
			value: values.target_dataset_id,
			label: values.target_dataset_id,
		} );
	}

	return (
		<div style={ { maxWidth: '760px' } }>
			<h2>
				{ isEdit
					? __( 'Edit workflow', 'terraviz' )
					: __( 'New workflow', 'terraviz' ) }
			</h2>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<TextControl
				label={ __( 'Name', 'terraviz' ) }
				value={ values.name }
				onChange={ set( 'name' ) }
				help={
					fieldError( 'name' ) ||
					__( '3–120 characters.', 'terraviz' )
				}
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Description', 'terraviz' ) }
				value={ values.description }
				onChange={ set( 'description' ) }
				help={ fieldError( 'description' ) }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Target dataset', 'terraviz' ) }
				value={ values.target_dataset_id }
				options={ datasetOptions }
				onChange={ set( 'target_dataset_id' ) }
				help={
					fieldError( 'target_dataset_id' ) ||
					__( 'The dataset this workflow refreshes.', 'terraviz' )
				}
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Cadence', 'terraviz' ) }
				value={ values.schedule }
				options={ scheduleOptions }
				onChange={ set( 'schedule' ) }
				help={ fieldError( 'schedule' ) }
				__nextHasNoMarginBottom
			/>
			<div style={ { margin: '12px 0' } }>
				<ToggleControl
					label={ __( 'Enabled', 'terraviz' ) }
					help={ __(
						'When enabled, the workflow runs on its cadence. Disabled workflows can still be run manually.',
						'terraviz'
					) }
					checked={ values.enabled }
					onChange={ set( 'enabled' ) }
					__nextHasNoMarginBottom
				/>
			</div>

			<TextareaControl
				label={ __( 'Pipeline (JSON)', 'terraviz' ) }
				value={ values.pipeline_json }
				onChange={ set( 'pipeline_json' ) }
				rows={ 10 }
				help={
					fieldError( 'pipeline_json' ) ||
					__(
						'A { "stages": […] } pipeline. Use Validate to check it against the node’s allowlist.',
						'terraviz'
					)
				}
				className={
					fieldError( 'pipeline_json' )
						? 'terraviz-field-error terraviz-code'
						: 'terraviz-code'
				}
				style={ { fontFamily: 'monospace' } }
				__nextHasNoMarginBottom
			/>
			<TextareaControl
				label={ __( 'Metadata template (JSON)', 'terraviz' ) }
				value={ values.metadata_template }
				onChange={ set( 'metadata_template' ) }
				rows={ 6 }
				help={
					fieldError( 'metadata_template' ) ||
					__(
						'Catalog fields applied to the refreshed dataset (values: strings or string arrays).',
						'terraviz'
					)
				}
				className={
					fieldError( 'metadata_template' )
						? 'terraviz-field-error terraviz-code'
						: 'terraviz-code'
				}
				style={ { fontFamily: 'monospace' } }
				__nextHasNoMarginBottom
			/>

			<div style={ { display: 'flex', gap: '8px', marginTop: '16px' } }>
				<Button
					variant="primary"
					onClick={ handleSave }
					isBusy={ saving }
					disabled={ saving }
				>
					{ isEdit
						? __( 'Save changes', 'terraviz' )
						: __( 'Create workflow', 'terraviz' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ handleValidate }
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Validate', 'terraviz' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ handleFormat }
					disabled={ saving }
				>
					{ __( 'Format JSON', 'terraviz' ) }
				</Button>
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ saving }
				>
					{ __( 'Back to list', 'terraviz' ) }
				</Button>
			</div>

			{ isEdit && <WorkflowRuns id={ id } /> }
		</div>
	);
}
