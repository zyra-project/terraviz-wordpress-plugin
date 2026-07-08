/**
 * Media channels — the "Media channels" sub-tab of the Feeds screen (Newsroom).
 *
 * Panel-media suggestions draw video from a vetted allowlist of YouTube
 * channels: built-in curated agency channels (non-removable) plus the node's
 * own custom channels, added by pasting a channel URL. Configure-tier, proxied
 * server-side like the rest of the Feeds area.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Spinner, TextControl, Notice } from '@wordpress/components';
import {
	listMediaChannels,
	createMediaChannel,
	deleteMediaChannel,
	normalizeError,
} from './api';

export default function MediaChannels() {
	const [ channels, setChannels ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ url, setUrl ] = useState( '' );
	const [ adding, setAdding ] = useState( false );
	const [ busyId, setBusyId ] = useState( null );
	const [ fieldError, setFieldError ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	const refresh = useCallback( () => {
		setLoading( true );
		listMediaChannels()
			.then( ( res ) =>
				setChannels( Array.isArray( res.channels ) ? res.channels : [] )
			)
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		refresh();
	}, [ refresh ] );

	const add = () => {
		const trimmed = url.trim();
		if ( ! trimmed ) {
			return;
		}
		setAdding( true );
		setFieldError( null );
		setNotice( null );
		createMediaChannel( trimmed )
			.then( ( res ) => {
				const name =
					( res && res.channel && res.channel.channelName ) ||
					__( 'the channel', 'terraviz' );
				setUrl( '' );
				setNotice( {
					type: 'success',
					/* translators: %s: channel name. */
					text: sprintf( __( 'Added %s.', 'terraviz' ), name ),
				} );
				refresh();
			} )
			.catch( ( e ) => {
				const n = normalizeError( e );
				const hit = n.errors.find( ( err ) => err.field === 'url' );
				setFieldError( hit ? hit.message : n.message );
			} )
			.finally( () => setAdding( false ) );
	};

	const remove = ( channel ) => {
		const confirmed =
			// eslint-disable-next-line no-alert -- a native confirm is acceptable for a destructive wp-admin action.
			window.confirm( __( 'Remove this custom channel?', 'terraviz' ) );
		if ( ! confirmed ) {
			return;
		}
		setBusyId( channel.channelId );
		setNotice( null );
		deleteMediaChannel( channel.channelId )
			.then( () => refresh() )
			.catch( ( e ) =>
				setNotice( {
					type: 'error',
					text: normalizeError( e ).message,
				} )
			)
			.finally( () => setBusyId( null ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<div style={ { maxWidth: '640px' } }>
			<p style={ { color: '#646970', marginTop: 0 } }>
				{ __(
					'Panel-media suggestions include videos from these vetted YouTube channels. Add your own by pasting a channel URL.',
					'terraviz'
				) }
			</p>

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<ul
				style={ {
					margin: '12px 0',
					padding: 0,
					listStyle: 'none',
					border: '1px solid #dcdcde',
					borderRadius: '4px',
				} }
			>
				{ channels.length === 0 && (
					<li style={ { padding: '12px 16px', color: '#646970' } }>
						{ __( 'No channels configured.', 'terraviz' ) }
					</li>
				) }
				{ channels.map( ( channel, i ) => (
					<li
						key={ channel.channelId }
						style={ {
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'space-between',
							gap: '12px',
							padding: '10px 16px',
							borderTop: i > 0 ? '1px solid #f0f0f1' : 'none',
						} }
					>
						<span>
							<strong>
								{ channel.channelName || channel.channelId }
							</strong>
							{ channel.builtin && (
								<span
									style={ {
										marginLeft: '8px',
										fontSize: '11px',
										color: '#646970',
										textTransform: 'uppercase',
										letterSpacing: '0.04em',
									} }
								>
									{ __( 'Built-in', 'terraviz' ) }
								</span>
							) }
						</span>
						{ channel.builtin ? (
							<span
								aria-hidden="true"
								style={ { color: '#c3c4c7' } }
							>
								—
							</span>
						) : (
							<Button
								variant="link"
								isDestructive
								onClick={ () => remove( channel ) }
								disabled={ busyId === channel.channelId }
							>
								{ __( 'Remove', 'terraviz' ) }
							</Button>
						) }
					</li>
				) ) }
			</ul>

			<div
				style={ {
					display: 'flex',
					alignItems: 'flex-end',
					gap: '8px',
				} }
			>
				<div style={ { flex: 1 } }>
					<TextControl
						label={ __( 'Channel URL', 'terraviz' ) }
						type="url"
						value={ url }
						onChange={ ( v ) => setUrl( v ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								add();
							}
						} }
						help={
							fieldError ||
							__(
								'A youtube.com/channel/UC…, @handle, /c/name, or /user/name URL.',
								'terraviz'
							)
						}
						__nextHasNoMarginBottom
					/>
				</div>
				<Button
					variant="secondary"
					onClick={ add }
					isBusy={ adding }
					disabled={ adding || ! url.trim() }
				>
					{ __( 'Add channel', 'terraviz' ) }
				</Button>
			</div>
		</div>
	);
}
