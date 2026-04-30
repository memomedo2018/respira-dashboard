const app = document.getElementById('adminApp');

function escapeHTML(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function adminPassword() {
  const stored = window.localStorage.getItem('respiratech_admin_password') || '';
  if (stored) return stored;
  const input = window.prompt('أدخل كلمة مرور الإدارة');
  if (input) {
    window.localStorage.setItem('respiratech_admin_password', input);
    return input;
  }
  return '';
}

async function api(path, options = {}) {
  const password = adminPassword();
  const headers = {
    'Content-Type': 'application/json',
    'X-Admin-Password': password,
    ...(options.headers || {})
  };
  const response = await fetch(path, { ...options, headers });
  if (response.status === 401) {
    window.localStorage.removeItem('respiratech_admin_password');
    throw new Error('كلمة المرور غير صحيحة');
  }
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    throw new Error(payload.error || 'حدث خطأ غير متوقع');
  }
  return response.json();
}

function seoChecklist(article) {
  const markdown = article.content_markdown || '';
  const words = markdown.replace(/[#>*`\-\[\]\(\)]/g, ' ').split(/\s+/).filter(Boolean).length;
  return [
    ['Has title', !!article.title_ar],
    ['Meta title < 60', !!article.meta_title && article.meta_title.length <= 60],
    ['Meta description < 160', !!article.meta_description && article.meta_description.length <= 160],
    ['Has H1', /(^|\n)#\s+/.test(markdown)],
    ['Has H2', /(^|\n)##\s+/.test(markdown)],
    ['Has FAQ', Array.isArray(article.faq) && article.faq.length > 0],
    ['Has CTA', !!article.cta_text],
    ['Has internal links', Array.isArray(article.internal_links) && article.internal_links.length > 0],
    ['Has disclaimer', !!article.medical_disclaimer],
    ['Has category', !!article.category],
    ['Has slug', !!article.slug],
    ['1200+ words', words >= 1200]
  ];
}

function articleForm(article = {}) {
  return `
    <div class="admin-grid">
      <div><label>العنوان</label><input class="admin-input" id="title_ar" value="${escapeHTML(article.title_ar || '')}"></div>
      <div><label>Slug</label><input class="admin-input" id="slug" value="${escapeHTML(article.slug || '')}"></div>
      <div><label>Meta title</label><input class="admin-input" id="meta_title" value="${escapeHTML(article.meta_title || '')}"></div>
      <div><label>Meta description</label><input class="admin-input" id="meta_description" value="${escapeHTML(article.meta_description || '')}"></div>
      <div><label>Excerpt</label><textarea class="admin-textarea" id="excerpt">${escapeHTML(article.excerpt || '')}</textarea></div>
      <div><label>Category</label><input class="admin-input" id="category" value="${escapeHTML(article.category || '')}"></div>
      <div><label>Tags (comma separated)</label><input class="admin-input" id="tags" value="${escapeHTML((article.tags || []).join(', '))}"></div>
      <div><label>Status</label><select class="admin-select" id="status"><option value="draft" ${article.status === 'draft' ? 'selected' : ''}>draft</option><option value="published" ${article.status === 'published' ? 'selected' : ''}>published</option></select></div>
      <div style="grid-column:1/-1"><label>Content Markdown</label><textarea class="admin-textarea" id="content_markdown">${escapeHTML(article.content_markdown || '')}</textarea></div>
    </div>
  `;
}

function renderEditor(article) {
  const checklist = seoChecklist(article).map(([label, ok]) => `<li>${ok ? '✓' : '✗'} ${escapeHTML(label)}</li>`).join('');
  app.innerHTML = `
    <div class="toolbar">
      <button class="btn btn-primary" id="generateBtn" type="button">توليد مقال جديد</button>
      <button class="btn btn-secondary" id="refreshBtn" type="button">تحديث القائمة</button>
    </div>
    <div class="card">
      <h2>تحرير المقال</h2>
      ${articleForm(article)}
      <div class="mini-actions">
        <button class="btn btn-primary" id="saveArticleBtn" type="button">حفظ</button>
        <button class="btn btn-secondary" id="publishArticleBtn" type="button">تبديل الحالة</button>
        <button class="btn btn-secondary" id="deleteArticleBtn" type="button">حذف</button>
      </div>
    </div>
    <div class="card">
      <h3>SEO Checklist</h3>
      <ul class="seo-list">${checklist}</ul>
    </div>
  `;

  document.getElementById('saveArticleBtn').addEventListener('click', async () => {
    const payload = {
      ...article,
      title_ar: document.getElementById('title_ar').value.trim(),
      slug: document.getElementById('slug').value.trim(),
      meta_title: document.getElementById('meta_title').value.trim(),
      meta_description: document.getElementById('meta_description').value.trim(),
      excerpt: document.getElementById('excerpt').value.trim(),
      category: document.getElementById('category').value.trim(),
      tags: document.getElementById('tags').value.split(',').map((item) => item.trim()).filter(Boolean),
      status: document.getElementById('status').value,
      content_markdown: document.getElementById('content_markdown').value
    };
    await api('/api/blog/save', { method: 'POST', body: JSON.stringify(payload) });
    await loadAdmin();
  });

  document.getElementById('publishArticleBtn').addEventListener('click', async () => {
    await api('/api/blog/toggle-status', { method: 'POST', body: JSON.stringify({ slug: article.slug }) });
    await loadAdmin();
  });

  document.getElementById('deleteArticleBtn').addEventListener('click', async () => {
    if (!window.confirm('تأكيد حذف المقال؟')) return;
    await api('/api/blog/delete', { method: 'POST', body: JSON.stringify({ slug: article.slug }) });
    await loadAdmin();
  });

  document.getElementById('generateBtn').addEventListener('click', async () => {
    await api('/api/blog/generate', { method: 'POST', body: JSON.stringify({ manual: true }) });
    await loadAdmin();
  });

  document.getElementById('refreshBtn').addEventListener('click', loadAdmin);
}

async function loadAdmin(articleSlug = '') {
  try {
    const data = await api('/api/blog');
    const articles = data.articles || [];
    const selected = articles.find((item) => item.slug === articleSlug) || articles[0] || {};
    const list = articles.map((article) => `
      <div class="card">
        <strong>${escapeHTML(article.title_ar)}</strong>
        <span class="status-chip">${escapeHTML(article.status || 'draft')}</span>
        <div style="color:#64748b;margin-top:.4rem">${escapeHTML(article.category || '')} · SEO ${escapeHTML(String(article.seo_score || 0))}</div>
        <div class="mini-actions">
          <button class="btn btn-secondary" type="button" data-edit="${escapeHTML(article.slug)}">فتح</button>
          <a class="btn btn-secondary" href="/blog/${escapeHTML(article.slug)}/" target="_blank" rel="noopener">معاينة</a>
        </div>
      </div>
    `).join('');
    app.innerHTML = `
      <div class="toolbar">
        <button class="btn btn-primary" id="generateBtn" type="button">توليد مقال جديد</button>
        <button class="btn btn-secondary" id="refreshBtn" type="button">تحديث القائمة</button>
      </div>
      <div class="admin-grid">
        <div>${list || '<div class="card">لا توجد مقالات بعد.</div>'}</div>
        <div>${selected.slug ? '' : '<div class="card">اختر مقالًا من القائمة أو أنشئ مقالًا جديدًا.</div>'}</div>
      </div>
    `;
    if (selected.slug) {
      renderEditor(selected);
    } else {
      document.getElementById('generateBtn')?.addEventListener('click', async () => {
        await api('/api/blog/generate', { method: 'POST', body: JSON.stringify({ manual: true }) });
        await loadAdmin();
      });
      document.getElementById('refreshBtn')?.addEventListener('click', loadAdmin);
    }

    app.querySelectorAll('[data-edit]').forEach((button) => {
      button.addEventListener('click', () => loadAdmin(button.dataset.edit));
    });
  } catch (error) {
    app.innerHTML = `<div class="card">${escapeHTML(error.message)}</div>`;
  }
}

loadAdmin();
