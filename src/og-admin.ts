import { EditorView, basicSetup } from 'codemirror';
import { html } from '@codemirror/lang-html';
import { oneDark } from '@codemirror/theme-one-dark';
import { keymap } from '@codemirror/view';
import { indentWithTab } from '@codemirror/commands';
import { EditorState } from '@codemirror/state';

declare const hanniesOg: {
    restUrl: string;
    nonce: string;
    defaultTemplate: string;
    postId: number;
    context: 'settings' | 'post';
};

// ---- Prettier (loaded async, browser standalone) ----

let prettierLoaded = false;
let prettierModule: any = null;
let prettierHtmlPlugin: any = null;

async function loadPrettier(): Promise<boolean> {
    if (prettierLoaded) return true;
    try {
        // prettier standalone bundles work in browser
        prettierModule = await import('prettier/standalone');
        prettierHtmlPlugin = await import('prettier/plugins/html');
        prettierLoaded = true;
        return true;
    } catch {
        // Prettier not available — degrade gracefully
        return false;
    }
}

async function formatHtml(code: string): Promise<string> {
    if (!(await loadPrettier())) {
        return naiveFormat(code);
    }
    try {
        return await prettierModule.format(code, {
            parser: 'html',
            plugins: [prettierHtmlPlugin],
            printWidth: 100,
            tabWidth: 2,
            singleQuote: false,
            htmlWhitespaceSensitivity: 'ignore',
        });
    } catch {
        return naiveFormat(code);
    }
}

/** Minimal fallback formatter when Prettier is unavailable. */
function naiveFormat(code: string): string {
    let formatted = '';
    let indent = 0;
    const lines = code
        .replace(/>\s*</g, '>\n<')
        .split('\n')
        .map(l => l.trim())
        .filter(Boolean);

    for (const line of lines) {
        if (line.startsWith('</')) indent = Math.max(0, indent - 1);
        formatted += '  '.repeat(indent) + line + '\n';
        if (
            line.startsWith('<') &&
            !line.startsWith('</') &&
            !line.startsWith('<!') &&
            !line.endsWith('/>') &&
            !line.includes('</') // self-closing or inline
        ) {
            indent++;
        }
    }
    return formatted.trimEnd() + '\n';
}

// ---- CM6 Theme Extension ----

const ogEditorTheme = EditorView.theme({
    '&': {
        fontSize: '13px',
        fontFamily: '"JetBrains Mono", "Fira Code", "SF Mono", "Menlo", monospace',
    },
    '.cm-scroller': {
        minHeight: '280px',
        maxHeight: '600px',
        overflow: 'auto',
    },
    '.cm-gutters': {
        borderRight: '1px solid #333',
    },
});

// ---- Editor Init ----

interface EditorInstance {
    view: EditorView;
    textarea: HTMLTextAreaElement;
}

function createEditor(container: HTMLElement, textarea: HTMLTextAreaElement): EditorInstance {
    const view = new EditorView({
        state: EditorState.create({
            doc: textarea.value,
            extensions: [
                basicSetup,
                html(),
                oneDark,
                ogEditorTheme,
                keymap.of([indentWithTab]),
                EditorView.updateListener.of(update => {
                    if (update.docChanged) {
                        textarea.value = update.state.doc.toString();
                    }
                }),
            ],
        }),
        parent: container,
    });

    return { view, textarea };
}

function setEditorContent(editor: EditorInstance, content: string): void {
    editor.view.dispatch({
        changes: {
            from: 0,
            to: editor.view.state.doc.length,
            insert: content,
        },
    });
}

// ---- Toolbar Actions ----

function wireFormatButton(btnId: string, editor: EditorInstance): void {
    const btn = document.getElementById(btnId) as HTMLButtonElement | null;
    if (!btn) return;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Formatting...';
        try {
            const code = editor.view.state.doc.toString();
            const formatted = await formatHtml(code);
            setEditorContent(editor, formatted);
        } finally {
            btn.textContent = 'Format';
            btn.disabled = false;
        }
    });
}

function wireResetButton(btnId: string, editor: EditorInstance, defaultTemplate: string): void {
    const btn = document.getElementById(btnId) as HTMLButtonElement | null;
    if (!btn) return;

    btn.addEventListener('click', () => {
        if (!confirm('Reset to the file-based default template? Your current edits will be replaced.')) return;
        setEditorContent(editor, defaultTemplate);
    });
}

function wirePreviewButton(
    btnId: string,
    imgId: string,
    wrapperId: string,
    editor: EditorInstance,
    postId?: number
): void {
    const btn = document.getElementById(btnId) as HTMLButtonElement | null;
    const img = document.getElementById(imgId) as HTMLImageElement | null;
    const wrapper = document.getElementById(wrapperId);
    if (!btn) return;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = 'Rendering...';

        try {
            const body: Record<string, unknown> = {
                template: editor.view.state.doc.toString(),
            };
            if (postId) body.post_id = postId;

            const resp = await fetch(hanniesOg.restUrl + 'og-preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': hanniesOg.nonce,
                },
                body: JSON.stringify(body),
            });

            if (!resp.ok) {
                const text = await resp.text();
                throw new Error(text || `HTTP ${resp.status}`);
            }

            const blob = await resp.blob();
            const url = URL.createObjectURL(blob);

            if (img) {
                // Revoke old blob URL to avoid memory leaks
                if (img.src.startsWith('blob:')) URL.revokeObjectURL(img.src);
                img.src = url;
            }
            if (wrapper) wrapper.style.display = 'block';
        } catch (err) {
            alert('Preview failed: ' + (err as Error).message);
        } finally {
            btn.textContent = 'Preview';
            btn.disabled = false;
        }
    });
}

// ---- Media Library / Image Picker ----

declare const wp: {
    media: (options: Record<string, unknown>) => {
        on: (event: string, cb: () => void) => void;
        open: () => void;
        state: () => {
            get: (key: string) => {
                first: () => { toJSON: () => { url: string; width: number; height: number } };
            };
        };
    };
};

function wireMediaButton(btnId: string, editor: EditorInstance): void {
    const btn = document.getElementById(btnId) as HTMLButtonElement | null;
    if (!btn) return;

    btn.addEventListener('click', () => {
        openMediaLibrary(editor);
    });
}

function openMediaLibrary(editor: EditorInstance): void {
    if (typeof wp === 'undefined' || !wp.media) {
        alert('WordPress media library not available.');
        return;
    }

    const frame = wp.media({
        title: 'Select Image for OG Template',
        button: { text: 'Insert Image' },
        multiple: false,
        library: { type: 'image' },
    });

    frame.on('select', () => {
        const attachment = frame.state().get('selection').first().toJSON();
        insertImageTag(editor, attachment.url, attachment.width, attachment.height);
    });

    frame.open();
}

function insertImageTag(editor: EditorInstance, url: string, width?: number, height?: number): void {
    const w = width || 200;
    const h = height || 200;
    const snippet = `<img src="${url}" tw="w-[${w}px] h-[${h}px]" />`;

    // Insert at cursor position
    const pos = editor.view.state.selection.main.head;
    editor.view.dispatch({
        changes: { from: pos, insert: snippet },
        selection: { anchor: pos + snippet.length },
    });
    editor.view.focus();
}

// ---- Init ----

document.addEventListener('DOMContentLoaded', () => {
    // Settings page editor
    const settingsTextarea = document.getElementById('hannies-og-template') as HTMLTextAreaElement | null;
    const settingsContainer = document.getElementById('hannies-og-cm-editor');

    if (settingsTextarea && settingsContainer) {
        const editor = createEditor(settingsContainer, settingsTextarea);

        wireFormatButton('hannies-og-format-btn', editor);
        wireResetButton('hannies-og-reset-btn', editor, hanniesOg.defaultTemplate || '');
        wireMediaButton('hannies-og-media-btn', editor);
        wirePreviewButton('hannies-og-preview-btn', 'hannies-og-preview-img', 'hannies-og-preview', editor);
    }

    // Per-post meta box editor
    const postTextarea = document.getElementById('hannies-og-post-template') as HTMLTextAreaElement | null;
    const postContainer = document.getElementById('hannies-og-post-cm-editor');

    if (postTextarea && postContainer) {
        const editor = createEditor(postContainer, postTextarea);

        wireFormatButton('hannies-og-post-format-btn', editor);
        wireResetButton('hannies-og-post-reset-btn', editor, hanniesOg.defaultTemplate || '');
        wireMediaButton('hannies-og-post-media-btn', editor);
        wirePreviewButton(
            'hannies-og-post-preview-btn',
            'hannies-og-post-preview-img',
            'hannies-og-post-preview',
            editor,
            hanniesOg.postId
        );

        // Toggle visibility + prefill with default when first enabled
        const checkbox = document.getElementById('hannies-og-use-custom') as HTMLInputElement | null;
        const wrap = document.getElementById('hannies-og-post-editor-wrap');
        if (checkbox && wrap) {
            checkbox.addEventListener('change', () => {
                wrap.style.display = checkbox.checked ? '' : 'none';

                // Prefill with default template if editor is empty
                if (checkbox.checked && editor.view.state.doc.length === 0 && hanniesOg.defaultTemplate) {
                    setEditorContent(editor, hanniesOg.defaultTemplate);
                }
            });
        }
    }
});
