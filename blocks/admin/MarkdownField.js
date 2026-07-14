/**
 * A Markdown editor field (EasyMDE) for the node profile's About text.
 *
 * EasyMDE gives a friendlier, "a-little-WYSIWYG" surface than a raw textarea —
 * inline syntax styling, a formatting toolbar, keyboard shortcuts, and an inline
 * (in-place, field-bounded) live preview — while keeping **Markdown as the
 * stored value** (the node renders it and it grounds AI drafts, so we never
 * convert to HTML).
 *
 * Self-contained on purpose:
 * - `autoDownloadFontAwesome: false` — EasyMDE otherwise fetches Font Awesome
 *   from a CDN, which the plugin's no-external-assets posture forbids.
 * - the toolbar uses **Dashicons** (already present in wp-admin), so no icon
 *   font is loaded from anywhere.
 * - `spellChecker: false` — EasyMDE's bundled checker would fetch dictionaries.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import EasyMDE from 'easymde';
import 'easymde/dist/easymde.min.css';
import './markdown-field.css';

// Curated toolbar: a Dashicons class per button (rendered by wp-admin's own
// icon font) + a title, and EasyMDE's built-in toggle actions.
function toolbar() {
	return [
		{
			name: 'bold',
			action: EasyMDE.toggleBold,
			className: 'dashicons dashicons-editor-bold',
			title: __( 'Bold', 'terraviz' ),
		},
		{
			name: 'italic',
			action: EasyMDE.toggleItalic,
			className: 'dashicons dashicons-editor-italic',
			title: __( 'Italic', 'terraviz' ),
		},
		{
			name: 'heading',
			action: EasyMDE.toggleHeadingSmaller,
			className: 'dashicons dashicons-heading',
			title: __( 'Heading', 'terraviz' ),
		},
		'|',
		{
			name: 'quote',
			action: EasyMDE.toggleBlockquote,
			className: 'dashicons dashicons-editor-quote',
			title: __( 'Quote', 'terraviz' ),
		},
		{
			name: 'unordered-list',
			action: EasyMDE.toggleUnorderedList,
			className: 'dashicons dashicons-editor-ul',
			title: __( 'Bulleted list', 'terraviz' ),
		},
		{
			name: 'ordered-list',
			action: EasyMDE.toggleOrderedList,
			className: 'dashicons dashicons-editor-ol',
			title: __( 'Numbered list', 'terraviz' ),
		},
		'|',
		{
			name: 'link',
			action: EasyMDE.drawLink,
			className: 'dashicons dashicons-admin-links',
			title: __( 'Link', 'terraviz' ),
		},
		{
			name: 'code',
			action: EasyMDE.toggleCodeBlock,
			className: 'dashicons dashicons-editor-code',
			title: __( 'Code', 'terraviz' ),
		},
		'|',
		{
			// The inline preview toggles *in place*, bounded to the field.
			// EasyMDE's side-by-side mode is fixed/fullscreen by design, so it's
			// deliberately omitted — it would take over the whole screen.
			name: 'preview',
			action: EasyMDE.togglePreview,
			className: 'dashicons dashicons-visibility no-disable',
			title: __( 'Toggle preview', 'terraviz' ),
		},
	];
}

/**
 * @param {Object}   props
 * @param {string}   props.value         Current Markdown value.
 * @param {Function} props.onChange      Called with the new Markdown on edit.
 * @param {string}   [props.placeholder] Placeholder text.
 * @param {string}   [props.label]       Accessible name for the field.
 */
export default function MarkdownField( {
	value,
	onChange,
	placeholder,
	label,
} ) {
	const textareaRef = useRef( null );
	const editorRef = useRef( null );
	// Keep the latest onChange without re-initialising the editor.
	const onChangeRef = useRef( onChange );
	onChangeRef.current = onChange;

	useEffect( () => {
		if ( ! textareaRef.current || editorRef.current ) {
			return undefined;
		}
		const editor = new EasyMDE( {
			element: textareaRef.current,
			initialValue: value || '',
			autoDownloadFontAwesome: false,
			spellChecker: false,
			status: false,
			minHeight: '160px',
			placeholder: placeholder || '',
			toolbar: toolbar(),
			// Keep the editor bounded to the field: EasyMDE's side-by-side (F9)
			// and fullscreen (F11) modes are fixed/fullscreen, so disable those
			// shortcuts (the buttons are already omitted). The inline preview
			// (Cmd/Ctrl+P) stays.
			shortcuts: {
				toggleSideBySide: null,
				toggleFullScreen: null,
			},
		} );
		editor.codemirror.on( 'change', () => {
			onChangeRef.current( editor.value() );
		} );
		editorRef.current = editor;

		return () => {
			// Restore the plain textarea and drop listeners on unmount.
			editor.toTextArea();
			editorRef.current = null;
		};
		// Mount once — EasyMDE is uncontrolled after init (the parent's value is
		// loaded before this mounts), so re-syncing the prop would fight the caret.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<div className="terraviz-markdown-field">
			<textarea
				ref={ textareaRef }
				defaultValue={ value || '' }
				aria-label={
					label || placeholder || __( 'Markdown editor', 'terraviz' )
				}
			/>
		</div>
	);
}
