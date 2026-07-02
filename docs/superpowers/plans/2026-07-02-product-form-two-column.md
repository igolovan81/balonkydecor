# Product Edit Form Two-Column Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the admin product edit form into two columns (main content left, stock/active/image status panel right) so the form uses the available screen width instead of leaving the right half of wide viewports empty, while keeping every other admin form unchanged.

**Architecture:** Pure template + CSS restructuring — `templates/admin/products/form.twig`'s existing `.form-group` blocks are regrouped into two sibling `<div>`s inside a new flex wrapper, and `www/assets/css/admin.css` gets a scoped modifier class plus a media-query collapse. No field, controller, model, or JS logic changes.

**Tech Stack:** Twig 3 templates, plain CSS (no preprocessor/build step).

## Global Constraints

- Applies to `templates/admin/products/form.twig` only. No other admin form (categories, gallery, blog, pages, users, settings) changes in any way.
- The base `.admin-form { max-width: 800px; }` rule in `www/assets/css/admin.css` is not modified — a new `.admin-form--wide` modifier class (max-width 1100px) is added instead, and only the product form's `<form>` tag gets both classes.
- Column split: main column (`flex: 2`) holds SKU, Price, Category, and the entire Translations section (lang tabs, per-language name/description/meta_title/meta_desc, translate button). Side column (`flex: 1`) holds Stock status dropdown, conditional Quantity field, Active checkbox, Add image input, and Existing images gallery.
- Field order within each column is unchanged from today's top-to-bottom order — only which column a field lands in changes.
- `.form-actions` (Save/Cancel) stays outside the two-column wrapper, directly inside `<form>`, so it always spans the full card width.
- Below 900px viewport width, the two columns stack (main above side) via `flex-direction: column`.
- No field `name` attributes, element `id`s (`stock-type-select`, `stock-qty-group`), or classes (`delete-image-btn`, `translate-btn`, `lang-tab`, `lang-panel`) change — all existing JS in this file's `{% block scripts %}` keeps working unmodified, since it selects by ID/class, not DOM position.

---

## File Structure

- `www/assets/css/admin.css` — new `.admin-form--wide`, `.product-form-columns`, `.product-form-main`, `.product-form-side` rules plus one `@media (max-width: 900px)` block.
- `templates/admin/products/form.twig` — full-file restructure: fields regrouped into two column `<div>`s; `{% block scripts %}` content is unchanged (only the surrounding HTML it targets moved, not the script itself).

---

## Task 1: Two-column CSS + template restructure

**Files:**
- Modify: `www/assets/css/admin.css:20-33` (Forms section)
- Modify: `templates/admin/products/form.twig` (full-file replacement)

**Interfaces:**
- Consumes: nothing new — this task only rearranges existing markup/CSS.
- Produces: `.admin-form--wide`, `.product-form-columns`, `.product-form-main`, `.product-form-side` CSS classes, usable by any future admin form that wants this same layout (none do yet, per Global Constraints).

No automated template/CSS tests exist in this repo (confirmed pattern throughout this project — no HTTP/functional/visual test harness). This task is verified by the full PHPUnit suite (sanity — this change touches no PHP) and by manual verification in Task 2.

- [ ] **Step 1: Add the two-column CSS rules**

In `www/assets/css/admin.css`, insert immediately after line 33 (`.admin-form .form-actions { display:flex; gap:0.75rem; margin-top:1.5rem; }`) and before the blank line that follows it:

```css
.admin-form--wide { max-width:1100px; }
.product-form-columns { display:flex; gap:2rem; }
.product-form-main { flex:2; min-width:0; }
.product-form-side { flex:1; min-width:0; }
@media (max-width:900px) {
  .product-form-columns { flex-direction:column; }
}
```

- [ ] **Step 2: Restructure the product form template**

Replace the full contents of `templates/admin/products/form.twig` with:

```twig
{% extends "layout/admin-base.twig" %}
{% block title %}{{ product ? t('products.form.title_edit') : t('products.form.title_new') }}{% endblock %}
{% block content %}
<div class="admin-topbar">
    <h1>{{ product ? t('products.form.title_edit') : t('products.form.title_new') }}</h1>
    <a href="/admin/products" class="btn btn-secondary">{{ t('products.form.back') }}</a>
</div>
<form method="POST" action="{{ product ? '/admin/products/' ~ product.id ~ '/edit' : '/admin/products/new' }}" enctype="multipart/form-data" class="admin-form admin-form--wide">
    <div class="product-form-columns">
        <div class="product-form-main">
            <div class="form-group">
                <label>{{ t('products.form.sku') }}</label>
                <input type="text" name="sku" value="{{ product.sku ?? '' }}" required>
            </div>
            <div class="form-group">
                <label>{{ t('products.form.price') }}</label>
                <input type="number" name="price" step="0.01" min="0" value="{{ product.price ?? '0.00' }}" required>
            </div>
            <div class="form-group">
                <label>{{ t('products.form.category') }}</label>
                <select name="category_id">
                    {% for cat in categories %}
                    <option value="{{ cat.id }}" {% if product.category_id == cat.id %}selected{% endif %}>{{ cat.name }}</option>
                    {% endfor %}
                </select>
            </div>
            <h3>{{ t('products.form.translations') }}</h3>
            <div class="lang-tabs">
                {% for lang in langs %}
                <button type="button" class="lang-tab {% if lang == admin_lang %}active preferred{% endif %}" data-lang="{{ lang }}">{% if lang == admin_lang %}★ {% endif %}{{ lang|upper }}</button>
                {% endfor %}
            </div>
            {% for lang in langs %}
            <div class="lang-panel {% if lang == admin_lang %}active{% endif %}" id="panel-{{ lang }}">
                <div class="form-group">
                    <label>{{ t('products.form.name_label') }} ({{ lang|upper }})</label>
                    <input type="text" name="t[{{ lang }}][name]" value="{{ translations[lang].name ?? '' }}">
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.desc_label') }} ({{ lang|upper }})</label>
                    <textarea name="t[{{ lang }}][description]">{{ translations[lang].description ?? '' }}</textarea>
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.meta_title_label') }} ({{ lang|upper }})</label>
                    <input type="text" name="t[{{ lang }}][meta_title]" value="{{ translations[lang].meta_title ?? '' }}" maxlength="255">
                </div>
                <div class="form-group">
                    <label>{{ t('products.form.meta_desc_label') }} ({{ lang|upper }})</label>
                    <textarea name="t[{{ lang }}][meta_desc]" maxlength="500">{{ translations[lang].meta_desc ?? '' }}</textarea>
                </div>
                {% if lang != admin_lang %}
                <div class="form-group">
                    <button type="button" class="btn btn-secondary translate-btn" data-lang="{{ lang }}">{{ t('products.form.translate_btn') }}</button>
                    <span class="translate-msg" style="display:none;margin-left:0.5rem;font-size:0.85rem;"></span>
                </div>
                {% endif %}
            </div>
            {% endfor %}
        </div>
        <div class="product-form-side">
            <div class="form-group">
                <label>{{ t('products.form.stock_label') }}</label>
                <select name="stock_type" id="stock-type-select">
                    <option value="unlimited" {% if (product.stock_type ?? 'unlimited') == 'unlimited' %}selected{% endif %}>{{ t('products.form.stock_unlimited') }}</option>
                    <option value="limited" {% if (product.stock_type ?? '') == 'limited' %}selected{% endif %}>{{ t('products.form.stock_limited') }}</option>
                </select>
            </div>
            <div class="form-group" id="stock-qty-group" style="{% if (product.stock_type ?? 'unlimited') != 'limited' %}display:none;{% endif %}">
                <label>{{ t('products.form.stock_qty_label') }}</label>
                <input type="number" name="stock_qty" min="0" step="1" value="{{ product.stock_qty ?? 0 }}">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" {% if product is null or product.is_active %}checked{% endif %}>
                    {{ t('products.form.active') }}
                </label>
            </div>
            <div class="form-group">
                <label>{{ t('products.form.add_image') }}</label>
                <input type="file" name="image" accept="image/*">
            </div>
            {% if product and product.images %}
            <div class="form-group">
                <label>{{ t('products.form.existing_images') }}</label>
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
                {% for img in product.images %}
                <div style="text-align:center;">
                    <img src="/assets/uploads/products/thumb_{{ img.filename }}" class="img-thumb"><br>
                    <button type="button" class="btn-link delete-image-btn" style="font-size:0.8rem" data-url="/admin/products/{{ product.id }}/image/{{ img.id }}/delete">{{ t('products.form.delete_image') }}</button>
                </div>
                {% endfor %}
                </div>
            </div>
            {% endif %}
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">{{ t('products.form.save') }}</button>
        <a href="/admin/products" class="btn btn-secondary">{{ t('products.form.cancel') }}</a>
    </div>
</form>
{% endblock %}
{% block scripts %}
<script>
const PREFERRED_LANG = "{{ admin_lang }}";

// Delete-image buttons — plain fetch(), not a nested <form> (forms can't nest in valid HTML)
document.querySelectorAll('.delete-image-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        await fetch(btn.dataset.url, { method: 'POST' });
        location.reload();
    });
});

document.querySelectorAll('.lang-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.lang-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.lang-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.lang).classList.add('active');
    });
});

// Stock quantity field toggle
(function () {
    const stockTypeSelect = document.getElementById('stock-type-select');
    const stockQtyGroup   = document.getElementById('stock-qty-group');
    if (!stockTypeSelect || !stockQtyGroup) return;

    function syncStockQtyVisibility() {
        stockQtyGroup.style.display = stockTypeSelect.value === 'limited' ? '' : 'none';
    }

    stockTypeSelect.addEventListener('change', syncStockQtyVisibility);
    syncStockQtyVisibility();
})();

// Translate buttons — JS runtime strings remain hardcoded Czech per spec
document.querySelectorAll('.translate-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        const targetLang = btn.dataset.lang;
        const panel       = document.getElementById('panel-' + targetLang);
        const msgSpan     = panel.querySelector('.translate-msg');
        const fields      = [
            { name: 'name',        el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][name]"]') },
            { name: 'description', el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][description]"]') },
            { name: 'meta_title',  el: document.querySelector('input[name="t[' + PREFERRED_LANG + '][meta_title]"]') },
            { name: 'meta_desc',   el: document.querySelector('textarea[name="t[' + PREFERRED_LANG + '][meta_desc]"]') },
        ];
        const filled = fields.filter(f => f.el.value.trim() !== '');

        if (filled.length === 0) {
            msgSpan.textContent = 'Nejprve vyplňte texty ve výchozím jazyce.';
            msgSpan.style.color = '#c00';
            msgSpan.style.display = 'inline';
            return;
        }

        btn.disabled = true;
        const originalLabel = btn.textContent;
        btn.textContent = 'Překládám…';
        msgSpan.style.display = 'none';
        msgSpan.textContent   = '';

        try {
            const res = await fetch('/admin/translate', {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify({
                    texts:  filled.map(f => f.el.value),
                    target: targetLang.toUpperCase(),
                }),
            });

            const data = await res.json();

            if (!res.ok || data.error) {
                msgSpan.textContent   = 'Překlad se nezdařil: ' + (data.error ?? res.status);
                msgSpan.style.color   = '#c00';
                msgSpan.style.display = 'inline';
                return;
            }

            filled.forEach((f, i) => {
                panel.querySelector('[name="t[' + targetLang + '][' + f.name + ']"]').value = data.texts[i] ?? '';
            });
            msgSpan.style.display = 'none';
            msgSpan.textContent   = '';
        } catch (e) {
            msgSpan.textContent   = 'Překlad se nezdařil: ' + e.message;
            msgSpan.style.color   = '#c00';
            msgSpan.style.display = 'inline';
        } finally {
            btn.disabled    = false;
            btn.textContent = originalLabel;
        }
    });
});
</script>
{% endblock %}
```

- [ ] **Step 3: Run the full test suite**

Run: `php vendor/bin/phpunit --testdox`
Expected: PASS (this task touches no PHP; this confirms nothing else broke).

- [ ] **Step 4: Commit**

```bash
git add www/assets/css/admin.css templates/admin/products/form.twig
git commit -m "feat: restructure admin product form into a two-column layout"
```

---

## Task 2: Manual verification

**Files:** none (verification only, per this project's guidance that UI changes must be checked against a running app before being called done).

- [ ] **Step 1: Start the local stack**

```bash
docker compose up -d
php -S localhost:8080 -t www
```

- [ ] **Step 2: Log in to the admin panel**

Visit `http://localhost:8080/admin/login` and log in (or `/admin/setup` first if no admin user exists).

- [ ] **Step 3: Verify the new product form (create)**

Open `/admin/products/new`. Confirm: the card is visibly wider than before and SKU/Price/Category/Translations appear on the left, Stock/Active/Add image appear on the right, side by side. Confirm the stock quantity field still shows/hides correctly when switching the stock dropdown (unchanged JS).

- [ ] **Step 4: Verify the new product form (edit, with an existing image)**

Create a product with an image (or open one that already has one). Confirm the "Existing images" thumbnail and its Delete button render in the right column, and that clicking Delete still removes the image and reloads the page correctly (this exercises the fetch()-based delete-image JS fixed earlier this session, now inside the new column markup).

- [ ] **Step 5: Verify column collapse at narrow width**

Resize the browser window (or use dev tools device toolbar) to below 900px width. Confirm the right-column fields (Stock, Active, Add image, Existing images) move to appear below the left-column fields (SKU, Price, Category, Translations) rather than being squeezed side-by-side.

- [ ] **Step 6: Verify no other admin form changed**

Open `/admin/categories/new`, `/admin/gallery/new`. Confirm both still render as a single narrow column exactly as before (no `.admin-form--wide` class, no `.product-form-columns` wrapper — those templates were not touched).

- [ ] **Step 7: Verify Save still works end-to-end**

Fill in and save a product from the new two-column form. Confirm it redirects to the product list with a success flash message, and that the saved values (including ones from the right column, like stock type/quantity/active) persisted correctly.

This task has no commit — it's a verification pass over the work committed in Task 1.

---

## Self-Review Notes

- **Spec coverage:** two-column split with the exact field grouping from the spec (Task 1), scoped only to the product form via `.admin-form--wide` rather than touching the base `.admin-form` rule (Task 1), 900px collapse breakpoint (Task 1, verified in Task 2 Step 5), no other admin form touched (verified in Task 2 Step 6), no field/JS/controller/model changes (Task 1's template is a reorder of the exact same markup, verified by Task 2 Steps 3, 4, 7 exercising the stock toggle, delete-image, and save flows) — all covered.
- **Placeholder scan:** no TBD/TODO; Task 1 contains the complete CSS and the complete replacement template, including the full unchanged script block (not abbreviated), so nothing is left for the implementer to guess at.
- **Type consistency:** N/A (no PHP types in this plan) — checked instead that every `name`, `id`, and `class` attribute in Task 1's template exactly matches what the (unmodified) script block in the same task selects (`stock-type-select`, `stock-qty-group`, `delete-image-btn`, `lang-tab`, `lang-panel`, `translate-btn`, `translate-msg`, and every `t[{{ lang }}][...]` field name) — no drift introduced by the reordering.
