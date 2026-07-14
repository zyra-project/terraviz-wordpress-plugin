/**
 * Blog list (Newsroom → Blog), Slice 1 of the WordPress-posts model
 * (docs/BLOG_WORDPRESS_POSTS_PLAN.md).
 *
 * Blog authoring lives in WordPress: a Terraviz blog post *is* a WP post, and
 * the existing Phase-4 WP→node sync publishes a grounded stub that links home.
 * This read-only view surfaces the node's posts and points each back at its
 * WordPress editor (via the server-decorated `wp_edit_url`), with **View** to
 * the published post on the node. "New post" opens the WordPress editor; the
 * node→WP seed action ("Create WordPress post") arrives in Slice 2.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { listBlog, importBlogToWp, normalizeError } from './api';
import { safeHttpUrl } from './safeUrl';
import DraftWithAI from './DraftWithAI';

const STATUSES = [ 'draft', 'published' ];

const BADGE = {
	draft: { bg: '#f0f0f1', fg: '#50575e' },
	published: { bg: '#edfaef', fg: '#00690e' },
};

function statusLabel( status ) {
	return 'published' === status
		? __( 'Published', 'terraviz' )
		: __( 'Draft', 'terraviz' );
}

function StatusBadge( { status } ) {
	const c = BADGE[ status ] || BADGE.draft;
	return (
		<span
			style={ {
				display: 'inline-block',
				padding: '1px 8px',
				borderRadius: '9px',
				fontSize: '11px',
				fontWeight: 600,
				lineHeight: '18px',
				background: c.bg,
				color: c.fg,
			} }
		>
			{ statusLabel( status ) }
		</span>
	);
}

function FilterTile( { label, count, active, onClick } ) {
	return (
		<button
			type="button"
			aria-pressed={ active }
			onClick={ onClick }
			style={ {
				flex: '1 1 160px',
				textAlign: 'left',
				border: active ? '1px solid #2271b1' : '1px solid #dcdcde',
				boxShadow: active ? 'inset 0 0 0 1px #2271b1' : 'none',
				borderRadius: '4px',
				padding: '12px 16px',
				background: '#fff',
				cursor: 'pointer',
			} }
		>
			<div
				style={ {
					fontSize: '12px',
					textTransform: 'uppercase',
					letterSpacing: '0.04em',
					color: '#646970',
				} }
			>
				{ label }
			</div>
			<div
				style={ {
					fontSize: '24px',
					fontWeight: 700,
					lineHeight: 1.2,
					marginTop: '2px',
					color: '#1d2327',
				} }
			>
				{ count }
			</div>
		</button>
	);
}

function shortDate( iso ) {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '—';
	}
	return d.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}

export default function Blog( { boot } ) {
	const [ posts, setPosts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ filter, setFilter ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busyId, setBusyId ] = useState( null );
	const [ drafting, setDrafting ] = useState( false );

	const refresh = useCallback( () => {
		setLoading( true );
		// Fetch all once; filter client-side so the tiles show true totals.
		listBlog()
			.then( ( res ) =>
				setPosts( Array.isArray( res.posts ) ? res.posts : [] )
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

	const counts = { draft: 0, published: 0 };
	posts.forEach( ( p ) => {
		if ( counts[ p.status ] !== undefined ) {
			counts[ p.status ] += 1;
		}
	} );
	const rows = filter ? posts.filter( ( p ) => p.status === filter ) : posts;
	const toggle = ( status ) => setFilter( filter === status ? '' : status );

	// Seed a WordPress draft from a node post, then hand the author straight to
	// the WP editor to finish and publish (the sync carries edits back).
	const createWpPost = ( post ) => {
		setBusyId( post.id );
		setNotice( null );
		importBlogToWp( post.id )
			.then( ( res ) => {
				const editUrl = safeHttpUrl( res && res.editUrl );
				if ( editUrl ) {
					window.location.assign( editUrl );
				} else {
					refresh();
				}
			} )
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
		<div>
			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					gap: '12px',
					flexWrap: 'wrap',
				} }
			>
				<p style={ { color: '#646970', margin: '4px 0 0' } }>
					{ __(
						'Long-form posts grounded in your datasets and current events. Authored in WordPress; published to the globe with a link home.',
						'terraviz'
					) }
				</p>
				<div
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: '8px',
					} }
				>
					{ filter && (
						<Button
							variant="link"
							onClick={ () => setFilter( '' ) }
						>
							{ __( 'Show all', 'terraviz' ) }
						</Button>
					) }
					<Button
						variant="secondary"
						onClick={ () => setDrafting( ( on ) => ! on ) }
						aria-expanded={ drafting }
					>
						{ __( 'Draft with AI', 'terraviz' ) }
					</Button>
					{ boot.newPostUrl && (
						<Button variant="primary" href={ boot.newPostUrl }>
							{ __( 'New post', 'terraviz' ) }
						</Button>
					) }
				</div>
			</div>

			{ drafting && (
				<DraftWithAI onCancel={ () => setDrafting( false ) } />
			) }

			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.text }
				</Notice>
			) }

			<div
				style={ {
					display: 'flex',
					gap: '16px',
					flexWrap: 'wrap',
					margin: '16px 0',
				} }
			>
				{ STATUSES.map( ( status ) => (
					<FilterTile
						key={ status }
						label={ statusLabel( status ) }
						count={ counts[ status ] }
						active={ filter === status }
						onClick={ () => toggle( status ) }
					/>
				) ) }
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Title', 'terraviz' ) }</th>
						<th style={ { width: '120px' } }>
							{ __( 'Status', 'terraviz' ) }
						</th>
						<th style={ { width: '130px' } }>
							{ __( 'Updated', 'terraviz' ) }
						</th>
						<th style={ { width: '220px' } }>
							{ __( 'Actions', 'terraviz' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.length === 0 && (
						<tr>
							<td colSpan="4">
								{ filter
									? __(
											'No posts in this status.',
											'terraviz'
									  )
									: __(
											'No blog posts yet. Write one in WordPress and opt it into Terraviz.',
											'terraviz'
									  ) }
							</td>
						</tr>
					) }
					{ rows.map( ( post ) => {
						const viewUrl =
							'published' === post.status && post.slug
								? safeHttpUrl(
										`${
											boot.origin
										}/blog/${ encodeURIComponent(
											post.slug
										) }`
								  )
								: null;
						// Use the sanitized (trimmed, http(s)-validated) URL, not the
						// raw meta value.
						const editUrl = safeHttpUrl( post.wp_edit_url );
						const actions = [];
						if ( editUrl ) {
							actions.push(
								<Button
									key="edit"
									variant="link"
									href={ editUrl }
								>
									{ __( 'Edit in WordPress', 'terraviz' ) }
								</Button>
							);
						} else {
							// No linked WP post yet: offer to seed one from the
							// node draft (Terraviz drives the initial content).
							actions.push(
								<Button
									key="create"
									variant="link"
									onClick={ () => createWpPost( post ) }
									isBusy={ busyId === post.id }
									disabled={ busyId === post.id }
								>
									{ __(
										'Create WordPress post',
										'terraviz'
									) }
								</Button>
							);
						}
						if ( viewUrl ) {
							actions.push(
								<ExternalLink key="view" href={ viewUrl }>
									{ __( 'View', 'terraviz' ) }
								</ExternalLink>
							);
						}
						return (
							<tr key={ post.id }>
								<td>
									<strong>
										{ post.title || post.slug || post.id }
									</strong>
									{ ! editUrl && (
										<div
											style={ {
												color: '#646970',
												fontSize: '12px',
												marginTop: '2px',
											} }
										>
											{ __(
												'Not linked to a WordPress post',
												'terraviz'
											) }
										</div>
									) }
								</td>
								<td>
									<StatusBadge status={ post.status } />
								</td>
								<td>{ shortDate( post.updatedAt ) }</td>
								<td>
									<div
										style={ {
											display: 'flex',
											alignItems: 'center',
											gap: '8px',
											flexWrap: 'wrap',
										} }
									>
										{ actions.length > 0
											? actions.map( ( action, i ) => (
													<span
														key={ action.key }
														style={ {
															display:
																'inline-flex',
															alignItems:
																'center',
															gap: '8px',
														} }
													>
														{ i > 0 && (
															<span
																aria-hidden="true"
																style={ {
																	color: '#c3c4c7',
																} }
															>
																|
															</span>
														) }
														{ action }
													</span>
											  ) )
											: '—' }
									</div>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			<p style={ { color: '#646970', marginTop: '8px' } }>
				{ sprintf(
					/* translators: %d: number of posts shown. */
					_n( '%d post', '%d posts', rows.length, 'terraviz' ),
					rows.length
				) }
			</p>
		</div>
	);
}
