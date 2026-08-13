/*
 * plottinggroups – #-tag autocomplete for the post editor.
 *
 * When the user types #, a dropdown opens and suggests approved plotting groups
 * (queried from xmlhttp.php?action=plottinggroups_suggest). Selecting one inserts
 * #"Group name" – the exact syntax the server-side tag scanner recognises.
 *
 * Works on the plain MyBB textarea (#message) and, when present, on the SCEditor
 * WYSIWYG body (window.MyBBEditor). No jQuery dependency.
 */
(function () {
    'use strict';

    var cfg = window.PlottinggroupsAutocomplete_config || {};
    var URL = cfg.url || 'xmlhttp.php?action=plottinggroups_suggest';
    var MIN_LENGTH = typeof cfg.minLength === 'number' ? cfg.minLength : 0;
    var DELAY = typeof cfg.delay === 'number' ? cfg.delay : 200;
    var LANG = {
        loading: cfg.loading || 'Searching…',
        noResults: cfg.noResults || 'No matching groups'
    };

    // The trigger character and how far back we scan for it.
    var TRIGGER = '#';
    var MAX_TOKEN = 60;

    var box = null;          // dropdown element (in the top document)
    var items = [];          // current suggestion data
    var activeIndex = -1;
    var searchTimer = null;
    var lastQuery = null;
    var xhr = null;

    // The editor context we are currently completing in.
    // { type: 'textarea'|'wysiwyg', ... } – see openFor().
    var ctx = null;

    /* ------------------------------------------------------------------ */
    /* Dropdown UI                                                        */
    /* ------------------------------------------------------------------ */

    function ensureBox() {
        if (box) {
            return box;
        }
        box = document.createElement('div');
        box.className = 'plottinggroups_ac';
        box.style.display = 'none';
        // Mouse selection. mousedown (not click) so the editor never loses focus
        // before we insert.
        box.addEventListener('mousedown', function (e) {
            var li = e.target;
            while (li && li !== box && li.getAttribute('data-index') === null) {
                li = li.parentNode;
            }
            if (li && li !== box) {
                e.preventDefault();
                choose(parseInt(li.getAttribute('data-index'), 10));
            }
        });
        document.body.appendChild(box);
        return box;
    }

    function renderMessage(text) {
        var b = ensureBox();
        b.innerHTML = '';
        var div = document.createElement('div');
        div.className = 'plottinggroups_ac_msg';
        div.textContent = text;
        b.appendChild(div);
    }

    function renderItems() {
        var b = ensureBox();
        b.innerHTML = '';
        var ul = document.createElement('ul');
        for (var i = 0; i < items.length; i++) {
            var li = document.createElement('li');
            li.setAttribute('data-index', i);
            li.className = 'plottinggroups_ac_item' + (i === activeIndex ? ' active' : '');
            li.textContent = items[i].name;
            ul.appendChild(li);
        }
        b.appendChild(ul);
    }

    function positionBox(rect) {
        var b = ensureBox();
        // rect is viewport-relative; the box is position:fixed so no scroll math.
        b.style.left = Math.round(rect.left) + 'px';
        b.style.top = Math.round(rect.bottom + 2) + 'px';
    }

    function showBox() {
        ensureBox().style.display = 'block';
    }

    function hideBox() {
        if (box) {
            box.style.display = 'none';
        }
        items = [];
        activeIndex = -1;
        ctx = null;
        lastQuery = null;
    }

    function isOpen() {
        return box && box.style.display === 'block';
    }

    function moveActive(delta) {
        if (!items.length) {
            return;
        }
        activeIndex = (activeIndex + delta + items.length) % items.length;
        renderItems();
    }

    /* ------------------------------------------------------------------ */
    /* Query                                                              */
    /* ------------------------------------------------------------------ */

    function requestSuggestions(query) {
        if (query === lastQuery) {
            return;
        }
        lastQuery = query;

        if (xhr) {
            xhr.abort();
        }
        renderMessage(LANG.loading);
        showBox();

        xhr = new XMLHttpRequest();
        xhr.open('GET', URL + '&query=' + encodeURIComponent(query), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            if (xhr.status !== 200) {
                hideBox();
                return;
            }
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                hideBox();
                return;
            }
            if (!ctx) {
                return; // context was closed while the request was in flight
            }
            items = (data && data.groups) ? data.groups : [];
            activeIndex = items.length ? 0 : -1;
            if (!items.length) {
                renderMessage(LANG.noResults);
            } else {
                renderItems();
            }
            showBox();
        };
        xhr.send();
    }

    function scheduleSearch(query) {
        if (searchTimer) {
            clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(function () {
            requestSuggestions(query);
        }, DELAY);
    }

    /* ------------------------------------------------------------------ */
    /* Token detection – find an open #token immediately before the caret */
    /* ------------------------------------------------------------------ */

    /**
     * Given the text before the caret, return the query typed after the last
     * unclosed #, or null when the caret is not inside a tag being typed.
     * A closing " ends the tag, so #"Done" no longer triggers.
     */
    function tokenFromText(before) {
        var hash = before.lastIndexOf(TRIGGER);
        if (hash === -1 || before.length - hash > MAX_TOKEN) {
            return null;
        }
        // Must be at line start or preceded by whitespace, so URLs/anchors don't trigger.
        if (hash > 0 && !/\s/.test(before.charAt(hash - 1))) {
            return null;
        }
        var rest = before.slice(hash + 1);
        // Optional opening quote, then the query. A second quote closes the tag.
        var m = /^"?([^"\n]*)$/.exec(rest);
        if (!m) {
            return null;
        }
        return { start: hash, query: m[1] };
    }

    /* ------------------------------------------------------------------ */
    /* Textarea backend                                                   */
    /* ------------------------------------------------------------------ */

    function handleTextarea(ta) {
        if (isOpen() && ctx && ctx.type !== 'textarea') {
            hideBox();
        }
        var caret = ta.selectionStart;
        var before = ta.value.slice(0, caret);
        var token = tokenFromText(before);
        if (!token) {
            hideBox();
            return;
        }
        ctx = { type: 'textarea', el: ta, start: token.start, end: caret };
        positionBox(textareaCaretRect(ta, token.start));
        if (token.query.length >= MIN_LENGTH) {
            scheduleSearch(token.query);
        } else {
            hideBox();
        }
    }

    function insertIntoTextarea(name) {
        var ta = ctx.el;
        var value = ta.value;
        var replacement = TRIGGER + '"' + name + '" ';
        ta.value = value.slice(0, ctx.start) + replacement + value.slice(ctx.end);
        var caret = ctx.start + replacement.length;
        ta.focus();
        ta.setSelectionRange(caret, caret);
    }

    /**
     * Pixel position of a character in a textarea, via a mirror element.
     * Returns a viewport-relative rect ({left, bottom}) for the dropdown.
     */
    function textareaCaretRect(ta, index) {
        var mirror = document.createElement('div');
        var style = window.getComputedStyle(ta);
        var props = ['boxSizing', 'width', 'paddingTop', 'paddingRight',
            'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth',
            'borderBottomWidth', 'borderLeftWidth', 'fontFamily', 'fontSize',
            'fontWeight', 'lineHeight', 'letterSpacing', 'textTransform',
            'wordSpacing', 'whiteSpace', 'wordWrap'];
        for (var i = 0; i < props.length; i++) {
            mirror.style[props[i]] = style[props[i]];
        }
        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.wordWrap = 'break-word';
        mirror.style.overflow = 'hidden';

        mirror.textContent = ta.value.slice(0, index);
        var marker = document.createElement('span');
        marker.textContent = ta.value.slice(index) || '.';
        mirror.appendChild(marker);
        document.body.appendChild(mirror);

        var taRect = ta.getBoundingClientRect();
        mirror.style.left = (taRect.left + window.pageXOffset) + 'px';
        mirror.style.top = (taRect.top + window.pageYOffset) + 'px';

        var markerRect = marker.getBoundingClientRect();
        var rect = {
            left: markerRect.left - ta.scrollLeft,
            bottom: markerRect.bottom - ta.scrollTop
        };
        document.body.removeChild(mirror);
        return rect;
    }

    /* ------------------------------------------------------------------ */
    /* SCEditor (WYSIWYG) backend                                         */
    /* ------------------------------------------------------------------ */

    function handleWysiwyg(editor) {
        var body = editor.getBody();
        body = (body && body.jquery) ? body[0] : body;
        if (!body) {
            hideBox();
            return;
        }
        var doc = body.ownerDocument;
        var sel = doc.getSelection ? doc.getSelection() : null;
        if (!sel || !sel.rangeCount) {
            hideBox();
            return;
        }
        var range = sel.getRangeAt(0);
        var node = range.startContainer;
        if (node.nodeType !== 3) { // only complete inside a text node
            hideBox();
            return;
        }
        var before = node.textContent.slice(0, range.startOffset);
        var token = tokenFromText(before);
        if (!token) {
            hideBox();
            return;
        }
        ctx = {
            type: 'wysiwyg',
            editor: editor,
            node: node,
            start: token.start,
            end: range.startOffset
        };

        // Caret rect: use a zero-width range at the caret, offset by the iframe.
        var iframe = findEditorIframe(body);
        var caretRange = doc.createRange();
        caretRange.setStart(node, token.start);
        caretRange.setEnd(node, range.startOffset);
        var r = caretRange.getBoundingClientRect();
        var frameRect = iframe ? iframe.getBoundingClientRect() : { left: 0, top: 0 };
        positionBox({
            left: frameRect.left + r.left,
            bottom: frameRect.top + r.bottom
        });

        if (token.query.length >= MIN_LENGTH) {
            scheduleSearch(token.query);
        } else {
            hideBox();
        }
    }

    function findEditorIframe(body) {
        var doc = body.ownerDocument;
        var frames = document.getElementsByTagName('iframe');
        for (var i = 0; i < frames.length; i++) {
            try {
                if (frames[i].contentDocument === doc) {
                    return frames[i];
                }
            } catch (e) { /* cross-origin – not ours */ }
        }
        return null;
    }

    function insertIntoWysiwyg(name) {
        var node = ctx.node;
        var text = node.textContent;
        var replacement = TRIGGER + '"' + name + '" ';
        node.textContent = text.slice(0, ctx.start) + replacement + text.slice(ctx.end);

        // Put the caret right after the inserted tag.
        var doc = node.ownerDocument;
        var sel = doc.getSelection();
        var range = doc.createRange();
        var caret = ctx.start + replacement.length;
        range.setStart(node, Math.min(caret, node.textContent.length));
        range.collapse(true);
        sel.removeAllRanges();
        sel.addRange(range);

        ctx.editor.getBody().focus ? ctx.editor.getBody().focus() : null;
    }

    /* ------------------------------------------------------------------ */
    /* Selection                                                          */
    /* ------------------------------------------------------------------ */

    function choose(index) {
        if (index < 0 || index >= items.length || !ctx) {
            return;
        }
        var name = items[index].name;
        var type = ctx.type;
        if (type === 'textarea') {
            insertIntoTextarea(name);
        } else {
            insertIntoWysiwyg(name);
        }
        hideBox();
    }

    /* ------------------------------------------------------------------ */
    /* Key handling (shared)                                              */
    /* ------------------------------------------------------------------ */

    function onKeydown(e) {
        if (!isOpen()) {
            return;
        }
        switch (e.keyCode) {
            case 38: // up
                e.preventDefault();
                moveActive(-1);
                break;
            case 40: // down
                e.preventDefault();
                moveActive(1);
                break;
            case 13: // enter
            case 9:  // tab
                if (items.length && activeIndex >= 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    choose(activeIndex);
                }
                break;
            case 27: // escape
                e.preventDefault();
                hideBox();
                break;
            default:
                break;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Wiring                                                             */
    /* ------------------------------------------------------------------ */

    function attachTextarea(ta) {
        if (!ta || ta.getAttribute('data-plottinggroups-ac')) {
            return;
        }
        ta.setAttribute('data-plottinggroups-ac', '1');
        ta.addEventListener('keyup', function (e) {
            // Ignore the navigation keys we consumed in keydown.
            if ([13, 9, 27, 38, 40].indexOf(e.keyCode) !== -1) {
                return;
            }
            handleTextarea(ta);
        });
        ta.addEventListener('keydown', onKeydown, true);
        ta.addEventListener('blur', function () { setTimeout(hideBox, 150); });
        ta.addEventListener('click', function () { handleTextarea(ta); });
    }

    function attachWysiwyg(editor) {
        var body = editor.getBody();
        body = (body && body.jquery) ? body[0] : body;
        if (!body || body.getAttribute('data-plottinggroups-ac')) {
            return;
        }
        body.setAttribute('data-plottinggroups-ac', '1');
        body.addEventListener('keyup', function (e) {
            if ([13, 9, 27, 38, 40].indexOf(e.keyCode) !== -1) {
                return;
            }
            handleWysiwyg(editor);
        });
        body.addEventListener('keydown', onKeydown, true);
        body.addEventListener('blur', function () { setTimeout(hideBox, 150); });
        body.addEventListener('click', function () { handleWysiwyg(editor); });
    }

    // SCEditor can switch between WYSIWYG and source (textarea) at runtime; the
    // source textarea is #message. Poll briefly so we attach to whatever exists.
    function bootstrap() {
        var textarea = document.getElementById('message');
        if (textarea) {
            attachTextarea(textarea);
        }

        var editor = window.MyBBEditor;
        if (editor && typeof editor.getBody === 'function') {
            try {
                attachWysiwyg(editor);
            } catch (e) { /* editor not ready yet */ }
        }

        // Reposition/hide on scroll & resize while open.
        window.addEventListener('scroll', function () { if (isOpen()) { hideBox(); } }, true);
    }

    var tries = 0;
    var poll = setInterval(function () {
        bootstrap();
        // Stop once we have attached to at least the textarea and, if sceditor is
        // used, its body – or give up after ~5s.
        var done = document.getElementById('message') &&
            (!window.MyBBEditor || (window.MyBBEditor.getBody &&
                (function () {
                    var b = window.MyBBEditor.getBody();
                    b = (b && b.jquery) ? b[0] : b;
                    return b && b.getAttribute('data-plottinggroups-ac');
                })()));
        if (done || ++tries > 25) {
            clearInterval(poll);
        }
    }, 200);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
