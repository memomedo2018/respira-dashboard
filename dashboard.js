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

async function adminApi(path, options = {}) {
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

async function publicApi(path, options = {}) {
  const response = await fetch(path, options);
  if (!response.ok) throw new Error('تعذر تحميل البيانات');
  return response.json();
}

function linesToArray(value) {
  return String(value || '')
    .split('\n')
    .map((item) => item.trim())
    .filter(Boolean);
}

function toSlug(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9\u0600-\u06ff._-]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
}

function seoChecklist(article) {
  const markdown = article.content_markdown || '';
  const words = markdown.replace(/[#>*`\-\[\]\(\)]/g, ' ').split(/\s+/).filter(Boolean).length;
  return [
    ['العنوان موجود', !!article.title_ar],
    ['Meta title أقل من 60', !!article.meta_title && article.meta_title.length <= 60],
    ['Meta description أقل من 160', !!article.meta_description && article.meta_description.length <= 160],
    ['يحتوي H1', /(^|\n)#\s+/.test(markdown)],
    ['يحتوي H2', /(^|\n)##\s+/.test(markdown)],
    ['FAQ موجود', Array.isArray(article.faq) && article.faq.length > 0],
    ['CTA موجود', !!article.cta_text],
    ['روابط داخلية موجودة', Array.isArray(article.internal_links) && article.internal_links.length > 0],
    ['Disclaimer موجود', !!article.medical_disclaimer],
    ['تصنيف موجود', !!article.category],
    ['Slug موجود', !!article.slug],
    ['1200 كلمة أو أكثر', words >= 1200]
  ];
}

const state = {
  storeConfig: {},
  categories: [],
  products: [],
  settings: {},
  logs: [],
  articles: [],
  seoBrain: { settings: {}, audit: {}, logs: [] },
  activityLogs: [],
  currentThumbnail: '',
  currentGallery: [],
  selectedArticle: null
};

const productFields = {
  editingId: document.getElementById('editingProductId'),
  nameAr: document.getElementById('productNameAr'),
  nameEn: document.getElementById('productNameEn'),
  slug: document.getElementById('productSlug'),
  brand: document.getElementById('productBrand'),
  categoryText: document.getElementById('productCategoryText'),
  productType: document.getElementById('productType'),
  shortDescription: document.getElementById('productShortDescription'),
  longDescription: document.getElementById('productLongDescription'),
  features: document.getElementById('productFeatures'),
  includedItems: document.getElementById('productIncludedItems'),
  sizes: document.getElementById('productSizes'),
  compatibility: document.getElementById('productCompatibility'),
  seoTitle: document.getElementById('productSeoTitle'),
  seoDescription: document.getElementById('productSeoDescription'),
  thumbnailFile: document.getElementById('productThumbnailFile'),
  galleryFiles: document.getElementById('productGalleryFiles')
};

const articleFields = {
  editingSlug: document.getElementById('editingArticleSlug'),
  title: document.getElementById('articleTitle'),
  slug: document.getElementById('articleSlug'),
  metaTitle: document.getElementById('articleMetaTitle'),
  metaDescription: document.getElementById('articleMetaDescription'),
  excerpt: document.getElementById('articleExcerpt'),
  category: document.getElementById('articleCategory'),
  tags: document.getElementById('articleTags'),
  content: document.getElementById('articleContent')
};

function renderMetrics() {
  const published = state.articles.filter((item) => item.status === 'published').length;
  const drafts = state.articles.filter((item) => item.status !== 'published').length;
  document.getElementById('metrics').innerHTML = `
    <div class="metric"><span class="label">عدد المنتجات</span><strong>${state.products.length}</strong></div>
    <div class="metric"><span class="label">المقالات المنشورة</span><strong>${published}</strong></div>
    <div class="metric"><span class="label">المقالات Draft</span><strong>${drafts}</strong></div>
    <div class="metric"><span class="label">التوليد اليومي</span><strong>${escapeHTML(String(state.settings.daily_blog_posts || 2))}</strong></div>
  `;
}

function renderTabs() {
  document.querySelectorAll('.tab-btn').forEach((button) => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.tab-btn').forEach((item) => item.classList.remove('is-active'));
      document.querySelectorAll('.tab-panel').forEach((panel) => panel.classList.remove('is-active'));
      button.classList.add('is-active');
      document.getElementById(`tab-${button.dataset.tab}`).classList.add('is-active');
    });
  });
}

function renderCategoryList() {
  const wrap = document.getElementById('categoriesList');
  if (!state.categories.length) {
    wrap.innerHTML = '<div class="empty-state">لا توجد تصنيفات.</div>';
    return;
  }
  wrap.innerHTML = state.categories.map((category) => `
    <div class="mini-card">
      <div class="mini-card-head">
        <div>
          <div class="mini-card-title">${escapeHTML(category.name_ar || category.name || category.id)}</div>
          <div class="mini-card-sub">${escapeHTML(category.id)}</div>
        </div>
        <span class="badge">${state.products.filter((product) => (product.filter_tags || []).includes(category.id)).length} منتج</span>
      </div>
    </div>
  `).join('');
}

function renderFilterTags() {
  const wrap = document.getElementById('filterTagsWrap');
  wrap.innerHTML = state.categories.map((category) => `
    <label class="toggle-row">
      <input type="checkbox" value="${escapeHTML(category.id)}" data-filter-tag>
      <span>${escapeHTML(category.name_ar || category.name || category.id)}</span>
    </label>
  `).join('');
}

function updateProductPreview() {
  document.getElementById('thumbnailPreview').src = state.currentThumbnail || '/assets/images/store/respira-tech-logo.png';
  const gallery = document.getElementById('galleryPreview');
  gallery.innerHTML = state.currentGallery.map((src) => `<img src="${escapeHTML(src)}" alt="">`).join('');
}

function clearProductForm() {
  productFields.editingId.value = '';
  productFields.nameAr.value = '';
  productFields.nameEn.value = '';
  productFields.slug.value = '';
  productFields.brand.value = 'ResMed';
  productFields.categoryText.value = '';
  productFields.productType.value = 'CPAP Mask';
  productFields.shortDescription.value = '';
  productFields.longDescription.value = '';
  productFields.features.value = '';
  productFields.includedItems.value = '';
  productFields.sizes.value = '';
  productFields.compatibility.value = '';
  productFields.seoTitle.value = '';
  productFields.seoDescription.value = '';
  productFields.thumbnailFile.value = '';
  productFields.galleryFiles.value = '';
  state.currentThumbnail = '';
  state.currentGallery = [];
  document.querySelectorAll('[data-filter-tag]').forEach((input) => { input.checked = false; });
  updateProductPreview();
}

function fillProductForm(product) {
  productFields.editingId.value = product.id || '';
  productFields.nameAr.value = product.name_ar || '';
  productFields.nameEn.value = product.name_en || '';
  productFields.slug.value = product.slug || product.id || '';
  productFields.brand.value = product.brand || 'ResMed';
  productFields.categoryText.value = product.category || '';
  productFields.productType.value = product.product_type || 'CPAP Mask';
  productFields.shortDescription.value = product.short_description_ar || '';
  productFields.longDescription.value = product.long_description_ar || '';
  productFields.features.value = Array.isArray(product.key_features_ar) ? product.key_features_ar.join('\n') : '';
  productFields.includedItems.value = Array.isArray(product.included_items_ar) ? product.included_items_ar.join('\n') : '';
  productFields.sizes.value = Array.isArray(product.sizes) ? product.sizes.join('\n') : '';
  productFields.compatibility.value = product.compatibility_notes_ar || '';
  productFields.seoTitle.value = product.seo_title || '';
  productFields.seoDescription.value = product.seo_description || '';
  state.currentThumbnail = product.main_image || '';
  state.currentGallery = Array.isArray(product.gallery_images) ? product.gallery_images : [];
  document.querySelectorAll('[data-filter-tag]').forEach((input) => {
    input.checked = Array.isArray(product.filter_tags) && product.filter_tags.includes(input.value);
  });
  updateProductPreview();
}

function renderProductsList() {
  const wrap = document.getElementById('productsList');
  if (!state.products.length) {
    wrap.innerHTML = '<div class="empty-state">لا توجد منتجات بعد.</div>';
    return;
  }
  wrap.innerHTML = state.products.map((product) => `
    <div class="mini-card">
      <div class="mini-card-head">
        <div>
          <div class="mini-card-title">${escapeHTML(product.name_ar)}</div>
          <div class="mini-card-sub">${escapeHTML(product.name_en || '')}</div>
        </div>
        <span class="badge">${escapeHTML(product.brand || 'ResMed')}</span>
      </div>
      <div class="mini-card-sub">${escapeHTML(product.category || '')}</div>
      <div class="sizes-preview" style="margin-top:.6rem">${(product.sizes || []).slice(0, 4).map((item) => `<span class="chip">${escapeHTML(item)}</span>`).join('')}</div>
      <div class="mini-actions" style="margin-top:.9rem">
        <a class="btn" href="/store/${encodeURIComponent(product.slug || product.id)}/" target="_blank" rel="noopener">معاينة</a>
        <button class="btn" type="button" data-edit-product="${escapeHTML(product.id)}">تعديل</button>
        <button class="btn btn-danger" type="button" data-delete-product="${escapeHTML(product.id)}">حذف</button>
      </div>
    </div>
  `).join('');

  wrap.querySelectorAll('[data-edit-product]').forEach((button) => {
    button.addEventListener('click', () => {
      const product = state.products.find((item) => item.id === button.dataset.editProduct);
      if (!product) return;
      fillProductForm(product);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  wrap.querySelectorAll('[data-delete-product]').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!window.confirm('تأكيد حذف المنتج؟')) return;
      state.products = state.products.filter((item) => item.id !== button.dataset.deleteProduct);
      await saveStore();
      clearProductForm();
      renderProductsList();
      renderCategoryList();
      renderMetrics();
    });
  });
}

async function readFileAsDataURL(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

async function uploadFiles(files) {
  const encodedFiles = await Promise.all(files.map(async (file) => ({
    filename: file.name,
    content: await readFileAsDataURL(file)
  })));
  const response = await fetch('/api/upload', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ files: encodedFiles })
  });
  const payload = await response.json();
  return Array.isArray(payload.files) ? payload.files.map((item) => item.url) : [];
}

async function saveStore() {
  await fetch('/api/store/save', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      config: state.storeConfig,
      categories: state.categories,
      products: state.products
    })
  });
}

function collectProductPayload() {
  const slug = toSlug(productFields.slug.value || productFields.nameEn.value || productFields.nameAr.value);
  const filterTags = Array.from(document.querySelectorAll('[data-filter-tag]:checked')).map((input) => input.value);
  return {
    id: slug,
    slug,
    name_ar: productFields.nameAr.value.trim(),
    name_en: productFields.nameEn.value.trim(),
    brand: productFields.brand.value.trim() || 'ResMed',
    category: productFields.categoryText.value.trim(),
    product_type: productFields.productType.value.trim() || 'CPAP Mask',
    short_description_ar: productFields.shortDescription.value.trim(),
    long_description_ar: productFields.longDescription.value.trim(),
    key_features_ar: linesToArray(productFields.features.value),
    included_items_ar: linesToArray(productFields.includedItems.value),
    sizes: linesToArray(productFields.sizes.value),
    compatibility_notes_ar: productFields.compatibility.value.trim(),
    price_text: state.storeConfig.price_text_default || 'السعر عند التواصل',
    main_image: state.currentThumbnail || '/assets/images/store/respira-tech-logo.png',
    gallery_images: state.currentGallery,
    whatsapp_message: `مرحبًا، أريد الاستفسار عن ${productFields.nameAr.value.trim()}`,
    seo_title: productFields.seoTitle.value.trim(),
    seo_description: productFields.seoDescription.value.trim(),
    filter_tags: filterTags
  };
}

function renderSettings() {
  document.getElementById('settingDailyCount').value = state.settings.daily_blog_posts || 2;
  document.getElementById('settingTextModel').value = state.settings.openai_text_model || 'gpt-4.1';
  document.getElementById('settingImageModel').value = state.settings.openai_image_model || 'dall-e-3';
  document.getElementById('settingAutoPublish').checked = !!state.settings.auto_publish_blogs;
  document.getElementById('settingGenerateImages').checked = state.settings.generate_blog_images !== false;
  document.getElementById('settingWhatsapp').value = state.settings.whatsapp_number || '';
  document.getElementById('settingBaseUrl').value = state.settings.site_base_url || '';
  document.getElementById('settingGithubRepo').value = state.settings.github_repo || '';
  document.getElementById('settingGithubBranch').value = state.settings.github_branch || 'main';
  document.getElementById('settingAutoPushChanges').checked = state.settings.auto_push_changes !== false;
  document.getElementById('settingOpenAiKey').placeholder = state.settings.openai_api_key_set ? 'المفتاح محفوظ بالفعل' : 'الصق المفتاح هنا';
  document.getElementById('settingGithubToken').placeholder = state.settings.github_sync_configured ? 'التوكن محفوظ بالفعل' : 'الصق التوكن هنا';
}

function renderLogs() {
  const wrap = document.getElementById('logsList');
  if (!state.logs.length) {
    wrap.innerHTML = '<div class="empty-state">لا يوجد سجل تشغيل حتى الآن.</div>';
    return;
  }
  wrap.innerHTML = state.logs.map((log) => `
    <div class="log-item">
      <strong>${escapeHTML(log.type || 'log')}</strong>
      <div class="mini-card-sub">${escapeHTML(log.created_at || '')}</div>
      ${log.message ? `<div style="margin-top:.35rem">${escapeHTML(log.message)}</div>` : ''}
      ${Array.isArray(log.items) ? `<div style="margin-top:.45rem">${log.items.map((item) => `<div class="mini-card-sub">${escapeHTML(item.slug)} · ${escapeHTML(item.status)}</div>`).join('')}</div>` : ''}
      ${log.error ? `<div style="margin-top:.45rem;color:#b91c1c">${escapeHTML(log.error)}</div>` : ''}
    </div>
  `).join('');
}

function renderSeoBrain() {
  const settings = state.seoBrain.settings || {};
  const audit = state.seoBrain.audit || {};
  const logs = Array.isArray(state.seoBrain.logs) ? state.seoBrain.logs : [];

  const seoAuto = document.getElementById('settingSeoBrainAuto');
  const seoRuns = document.getElementById('settingSeoRuns');
  const gscSiteUrl = document.getElementById('settingGscSiteUrl');
  if (seoAuto) seoAuto.checked = settings.seo_brain_auto !== false;
  if (seoRuns) seoRuns.value = settings.seo_brain_runs_per_day || 2;
  if (gscSiteUrl) gscSiteUrl.value = settings.gsc_site_url || state.settings.site_base_url || '';

  const summary = document.getElementById('seoAuditSummary');
  const pages = Array.isArray(audit.pages) ? audit.pages : [];
  const recs = Array.isArray(audit.recommendations) ? audit.recommendations : [];
  const lowScore = audit.content?.low_score_articles || [];
  const logoImages = audit.content?.logo_featured_images || [];
  const gsc = audit.search_console || {};
  summary.innerHTML = `
    <div class="mini-card">
      <div class="mini-card-title">آخر مراجعة</div>
      <div class="mini-card-sub">${escapeHTML(audit.created_at || 'لم يتم التشغيل بعد')}</div>
    </div>
    <div class="mini-card">
      <div class="mini-card-title">الصفحات الأساسية</div>
      <div class="mini-card-sub">${pages.map((page) => `${escapeHTML(page.url || '')} · ${escapeHTML(String(page.status || '0'))} · ${escapeHTML(String(page.load_ms || '—'))}ms`).join('<br>') || 'لا توجد بيانات بعد.'}</div>
    </div>
    <div class="mini-card">
      <div class="mini-card-title">المقالات التي تحتاج تحسين</div>
      <div class="mini-card-sub">${lowScore.length ? lowScore.map((item) => `${escapeHTML(item.title || item.slug)} · SEO ${escapeHTML(String(item.seo_score || 0))}`).join('<br>') : 'لا توجد مقالات منخفضة الدرجة حاليًا.'}</div>
    </div>
    <div class="mini-card">
      <div class="mini-card-title">صور المقالات</div>
      <div class="mini-card-sub">${logoImages.length ? `${logoImages.length} مقال ما زال يستخدم اللوجو بدل صورة مميزة` : 'لا توجد مقالات تستخدم اللوجو كصورة افتراضية.'}</div>
    </div>
    <div class="mini-card">
      <div class="mini-card-title">Search Console</div>
      <div class="mini-card-sub">${escapeHTML(gsc.message || (settings.gsc_credentials_set ? 'مربوط' : 'غير مربوط'))}</div>
      ${Array.isArray(gsc.queries) && gsc.queries.length ? `<div class="mini-card-sub" style="margin-top:.5rem">${gsc.queries.map((row) => `${escapeHTML(row.query)} · Pos ${escapeHTML(String(Number(row.position || 0).toFixed(1)))} · Clicks ${escapeHTML(String(row.clicks || 0))}`).join('<br>')}</div>` : ''}
    </div>
    <div class="mini-card">
      <div class="mini-card-title">التوصيات الحالية</div>
      <div class="mini-card-sub">${recs.length ? recs.map((item) => `• ${escapeHTML(item)}`).join('<br>') : 'لا توجد ملاحظات حرجة حاليًا.'}</div>
    </div>
  `;

  const seoLogs = document.getElementById('seoLogsList');
  seoLogs.innerHTML = logs.length ? logs.map((log) => `
    <div class="log-item">
      <strong>${escapeHTML(log.type || 'seo-log')}</strong>
      <div class="mini-card-sub">${escapeHTML(log.created_at || '')}</div>
      ${log.error ? `<div style="margin-top:.4rem;color:#b91c1c">${escapeHTML(log.error)}</div>` : ''}
      ${log.updated_count ? `<div style="margin-top:.4rem">تم تحديث ${escapeHTML(String(log.updated_count))} عنصر</div>` : ''}
      ${log.recommendations_count ? `<div style="margin-top:.4rem">عدد التوصيات ${escapeHTML(String(log.recommendations_count))}</div>` : ''}
      ${log.slug ? `<div style="margin-top:.4rem">${escapeHTML(log.slug)}</div>` : ''}
    </div>
  `).join('') : '<div class="empty-state">لا يوجد سجل SEO Brain بعد.</div>';
}

function sameLocalDay(value) {
  if (!value) return false;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return false;
  const now = new Date();
  return date.getFullYear() === now.getFullYear()
    && date.getMonth() === now.getMonth()
    && date.getDate() === now.getDate();
}

function formatDateTime(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return escapeHTML(String(value));
  return date.toLocaleString('ar-EG', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function actionLabel(action) {
  const labels = {
    store_save: 'حفظ المنتجات',
    article_save: 'حفظ مقال',
    article_toggle_status: 'تغيير حالة مقال',
    article_delete: 'حذف مقال',
    blog_generate_batch: 'توليد دفعة مقالات',
    gsc_credentials_upload: 'رفع بيانات Search Console',
    seo_audit: 'SEO Audit',
    seo_refresh_links: 'تحديث الروابط الداخلية',
    seo_full_run: 'تشغيل SEO Brain الكامل',
    seo_from_url: 'إنشاء مقال من رابط',
    dashboard_settings_update: 'حفظ الإعدادات',
    manual_rebuild: 'إعادة بناء الصفحات',
    cron_generate_blog: 'كرون توليد المقالات',
    cron_seo_brain: 'كرون SEO Brain'
  };
  return labels[action] || action || 'عملية';
}

function collectDailyTimeline() {
  const items = [];

  (Array.isArray(state.activityLogs) ? state.activityLogs : [])
    .filter((log) => sameLocalDay(log.created_at) && (log.status || 'success') === 'success')
    .forEach((log) => {
      const details = [];
      if (log.slug) details.push(log.slug);
      if (log.products_count) details.push(`${log.products_count} منتج`);
      if (log.generated_count) details.push(`${log.generated_count} مقال`);
      if (log.updated_count) details.push(`تحديث ${log.updated_count}`);
      items.push({
        created_at: log.created_at,
        title: actionLabel(log.action),
        detail: details.join(' · ') || 'تم التنفيذ بنجاح',
        badge: 'Activity'
      });
    });

  (Array.isArray(state.logs) ? state.logs : [])
    .filter((log) => sameLocalDay(log.created_at) && log.type === 'success')
    .forEach((log) => {
      items.push({
        created_at: log.created_at,
        title: 'توليد مقالات يومي',
        detail: `${log.generated_count || (Array.isArray(log.items) ? log.items.length : 0)} مقال`,
        badge: 'Blog'
      });
    });

  (Array.isArray(state.seoBrain.logs) ? state.seoBrain.logs : [])
    .filter((log) => sameLocalDay(log.created_at) && !log.error)
    .forEach((log) => {
      const details = [];
      if (log.slug) details.push(log.slug);
      if (log.updated_count) details.push(`تحديث ${log.updated_count}`);
      if (log.recommendations_count) details.push(`توصيات ${log.recommendations_count}`);
      items.push({
        created_at: log.created_at,
        title: `SEO Brain: ${actionLabel(`seo_${log.type || ''}`)}`,
        detail: details.join(' · ') || 'تم التنفيذ',
        badge: 'SEO'
      });
    });

  return items.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
}

function renderDailyReport() {
  const activityToday = (Array.isArray(state.activityLogs) ? state.activityLogs : []).filter((log) => sameLocalDay(log.created_at));
  const successfulActions = activityToday.filter((log) => (log.status || 'success') === 'success');
  const generatedRuns = (Array.isArray(state.logs) ? state.logs : []).filter((log) => sameLocalDay(log.created_at) && log.type === 'success');
  const generatedArticles = generatedRuns.reduce((sum, log) => sum + (Number(log.generated_count) || (Array.isArray(log.items) ? log.items.length : 0)), 0);
  const articlesCreatedToday = (Array.isArray(state.articles) ? state.articles : []).filter((article) => sameLocalDay(article.created_at));
  const articlesPublishedToday = (Array.isArray(state.articles) ? state.articles : []).filter((article) => sameLocalDay(article.published_at));
  const seoActionsToday = successfulActions.filter((log) => String(log.action || '').startsWith('seo_') || String(log.action || '').startsWith('cron_seo'));
  const githubPushesToday = successfulActions.filter((log) => !['gsc_credentials_upload'].includes(String(log.action || ''))).length;

  document.getElementById('dailyReportSummary').innerHTML = `
    <div class="metric"><span class="label">عمليات ناجحة اليوم</span><strong>${successfulActions.length}</strong></div>
    <div class="metric"><span class="label">مقالات تولدت اليوم</span><strong>${generatedArticles}</strong></div>
    <div class="metric"><span class="label">مقالات جديدة اليوم</span><strong>${articlesCreatedToday.length}</strong></div>
    <div class="metric"><span class="label">مقالات منشورة اليوم</span><strong>${articlesPublishedToday.length}</strong></div>
    <div class="metric"><span class="label">عمليات SEO اليوم</span><strong>${seoActionsToday.length}</strong></div>
    <div class="metric"><span class="label">Push إلى GitHub اليوم</span><strong>${githubPushesToday}</strong></div>
    <div class="metric"><span class="label">الربط التلقائي</span><strong>${state.settings.github_sync_configured ? 'مفعّل' : 'غير مكتمل'}</strong></div>
    <div class="metric"><span class="label">آخر مراجعة SEO</span><strong>${sameLocalDay(state.seoBrain.audit?.created_at) ? 'اليوم' : 'ليس اليوم'}</strong></div>
  `;

  document.getElementById('dailyReportNotes').innerHTML = `
    آخر تحديث للتقرير: ${escapeHTML(formatDateTime(new Date().toISOString()))}<br>
    الحفظ التلقائي إلى GitHub: ${state.settings.github_sync_configured ? 'مفعل، وأي عملية ناجحة تدفع التغيير ثم تؤدي إلى redeploy تلقائي على Railway.' : 'غير مكتمل بعد، لذلك بعض العمليات قد تنجح محليًا فقط.'}
  `;

  const timeline = collectDailyTimeline();
  document.getElementById('dailyReportTimeline').innerHTML = timeline.length ? timeline.map((item) => `
    <div class="log-item">
      <strong>${escapeHTML(item.title)}</strong>
      <div class="mini-card-sub">${escapeHTML(formatDateTime(item.created_at))} · ${escapeHTML(item.badge)}</div>
      <div style="margin-top:.35rem">${escapeHTML(item.detail)}</div>
    </div>
  `).join('') : '<div class="empty-state">لا توجد عمليات ناجحة مسجلة اليوم بعد.</div>';
}

function renderArticlesList() {
  const wrap = document.getElementById('articlesList');
  if (!state.articles.length) {
    wrap.innerHTML = '<div class="empty-state">لا توجد مقالات بعد.</div>';
    return;
  }
  wrap.innerHTML = state.articles.map((article) => `
    <div class="mini-card">
      <div class="mini-card-head">
        <div>
          <div class="mini-card-title">${escapeHTML(article.title_ar)}</div>
          <div class="mini-card-sub">${escapeHTML(article.category || '')}</div>
        </div>
        <span class="badge ${article.status === 'published' ? 'status-published' : 'status-draft'}">${escapeHTML(article.status || 'draft')}</span>
      </div>
      <div class="mini-card-sub">SEO ${escapeHTML(String(article.seo_score || 0))} · ${escapeHTML(String(article.reading_time || 0))} دقيقة</div>
      <div class="mini-actions" style="margin-top:.85rem">
        <button class="btn" type="button" data-open-article="${escapeHTML(article.slug)}">فتح</button>
        ${article.status === 'published' ? `<a class="btn" href="/blog/${escapeHTML(article.slug)}/" target="_blank" rel="noopener">معاينة</a>` : ''}
      </div>
    </div>
  `).join('');

  wrap.querySelectorAll('[data-open-article]').forEach((button) => {
    button.addEventListener('click', () => {
      const article = state.articles.find((item) => item.slug === button.dataset.openArticle);
      if (!article) return;
      selectArticle(article);
      document.querySelector('[data-tab="articles"]').click();
    });
  });
}

function selectArticle(article) {
  state.selectedArticle = article;
  articleFields.editingSlug.value = article.slug || '';
  articleFields.title.value = article.title_ar || '';
  articleFields.slug.value = article.slug || '';
  articleFields.metaTitle.value = article.meta_title || '';
  articleFields.metaDescription.value = article.meta_description || '';
  articleFields.excerpt.value = article.excerpt || '';
  articleFields.category.value = article.category || '';
  articleFields.tags.value = Array.isArray(article.tags) ? article.tags.join(', ') : '';
  articleFields.content.value = article.content_markdown || '';
  renderArticleChecklist(article);
}

function renderArticleChecklist(article) {
  const wrap = document.getElementById('articleSeoChecklist');
  wrap.innerHTML = seoChecklist(article).map(([label, ok]) => `
    <div class="mini-card">
      <div class="mini-card-title">${ok ? '✓' : '✗'} ${escapeHTML(label)}</div>
    </div>
  `).join('');
}

async function refreshDashboard() {
  const [storeData, dashboardData] = await Promise.all([
    publicApi('/api/store'),
    adminApi('/api/dashboard/config')
  ]);

  state.storeConfig = storeData.config || {};
  state.categories = Array.isArray(storeData.categories) ? storeData.categories : [];
  state.products = Array.isArray(storeData.products) ? storeData.products : [];
  state.settings = dashboardData.settings || {};
  state.logs = dashboardData.logs || [];
  state.articles = dashboardData.articles || [];
  state.seoBrain = dashboardData.seo_brain || { settings: {}, audit: {}, logs: [] };
  state.activityLogs = dashboardData.activity_logs || [];

  renderMetrics();
  renderCategoryList();
  renderFilterTags();
  renderProductsList();
  renderSettings();
  renderLogs();
  renderArticlesList();
  renderSeoBrain();
  renderDailyReport();
  if (state.selectedArticle) {
    const updated = state.articles.find((item) => item.slug === state.selectedArticle.slug);
    if (updated) selectArticle(updated);
  } else if (state.articles[0]) {
    selectArticle(state.articles[0]);
  } else {
    renderArticleChecklist({});
  }
}

document.getElementById('saveProductBtn').addEventListener('click', async () => {
  const payload = collectProductPayload();
  if (!payload.slug || !payload.name_ar) {
    window.alert('أدخل اسم المنتج والـ slug على الأقل.');
    return;
  }
  const existingId = productFields.editingId.value.trim();
  state.products = [payload, ...state.products.filter((item) => item.id !== existingId && item.id !== payload.id)];
  await saveStore();
  clearProductForm();
  await refreshDashboard();
});

document.getElementById('newProductBtn').addEventListener('click', clearProductForm);

productFields.nameEn.addEventListener('input', () => {
  if (!productFields.slug.value.trim()) {
    productFields.slug.value = toSlug(productFields.nameEn.value);
  }
});

productFields.thumbnailFile.addEventListener('change', async (event) => {
  const file = event.target.files?.[0];
  if (!file) return;
  const [url] = await uploadFiles([file]);
  state.currentThumbnail = url || '';
  updateProductPreview();
});

productFields.galleryFiles.addEventListener('change', async (event) => {
  const files = Array.from(event.target.files || []);
  if (!files.length) return;
  state.currentGallery = await uploadFiles(files);
  updateProductPreview();
});

document.getElementById('saveSettingsBtn').addEventListener('click', async () => {
  const payload = {
    openai_api_key: document.getElementById('settingOpenAiKey').value.trim(),
    auto_publish_blogs: document.getElementById('settingAutoPublish').checked,
    daily_blog_posts: Number(document.getElementById('settingDailyCount').value) || 2,
    generate_blog_images: document.getElementById('settingGenerateImages').checked,
    openai_text_model: document.getElementById('settingTextModel').value.trim(),
    openai_image_model: document.getElementById('settingImageModel').value.trim(),
    whatsapp_number: document.getElementById('settingWhatsapp').value.trim(),
    site_base_url: document.getElementById('settingBaseUrl').value.trim(),
    admin_password: document.getElementById('settingAdminPassword').value.trim(),
    cron_secret: document.getElementById('settingCronSecret').value.trim(),
    github_repo: document.getElementById('settingGithubRepo').value.trim(),
    github_branch: document.getElementById('settingGithubBranch').value.trim(),
    github_token: document.getElementById('settingGithubToken').value.trim(),
    auto_push_changes: document.getElementById('settingAutoPushChanges').checked,
    seo_brain_auto: document.getElementById('settingSeoBrainAuto')?.checked,
    seo_brain_runs_per_day: Number(document.getElementById('settingSeoRuns')?.value) || 2,
    gsc_site_url: document.getElementById('settingGscSiteUrl')?.value.trim()
  };
  await adminApi('/api/dashboard/config', { method: 'POST', body: JSON.stringify(payload) });
  document.getElementById('settingOpenAiKey').value = '';
  document.getElementById('settingAdminPassword').value = '';
  document.getElementById('settingCronSecret').value = '';
  document.getElementById('settingGithubToken').value = '';
  await refreshDashboard();
  window.alert('تم حفظ الإعدادات.');
});

async function runSeoBrain(action, extra = {}) {
  const response = await adminApi('/api/seo/brain', {
    method: 'POST',
    body: JSON.stringify({ action, ...extra })
  });
  state.seoBrain = response.state || state.seoBrain;
  renderSeoBrain();
  await refreshDashboard();
  return response;
}

document.getElementById('runSeoAuditBtn').addEventListener('click', async () => {
  await runSeoBrain('audit');
  window.alert('تم تشغيل SEO Audit.');
});

document.getElementById('refreshInternalLinksBtn').addEventListener('click', async () => {
  await runSeoBrain('refresh_links');
  window.alert('تم تحديث internal links للمقالات.');
});

document.getElementById('runSeoFullBtn').addEventListener('click', async () => {
  await runSeoBrain('full_run');
  window.alert('تم تشغيل SEO Brain الكامل.');
});

document.getElementById('generateFromUrlDraftBtn').addEventListener('click', async () => {
  const url = document.getElementById('sourceArticleUrl').value.trim();
  if (!url) return window.alert('أدخل رابط المصدر أولًا.');
  await runSeoBrain('from_url', { url, publish_now: false });
  document.getElementById('sourceArticleUrl').value = '';
  window.alert('تم إنشاء Draft من الرابط.');
});

document.getElementById('generateFromUrlPublishBtn').addEventListener('click', async () => {
  const url = document.getElementById('sourceArticleUrl').value.trim();
  if (!url) return window.alert('أدخل رابط المصدر أولًا.');
  await runSeoBrain('from_url', { url, publish_now: true });
  document.getElementById('sourceArticleUrl').value = '';
  window.alert('تم إنشاء المقال ونشره من الرابط.');
});

document.getElementById('uploadGscBtn').addEventListener('click', async () => {
  const content = document.getElementById('gscCredentialsJson').value.trim();
  if (!content) return window.alert('الصق JSON أولًا.');
  await adminApi('/api/seo/gsc/upload', { method: 'POST', body: JSON.stringify({ content }) });
  document.getElementById('gscCredentialsJson').value = '';
  await refreshDashboard();
  window.alert('تم حفظ Google Search Console credentials.');
});

document.getElementById('saveSeoSettingsBtn').addEventListener('click', async () => {
  await adminApi('/api/dashboard/config', {
    method: 'POST',
    body: JSON.stringify({
      auto_publish_blogs: document.getElementById('settingAutoPublish').checked,
      daily_blog_posts: Number(document.getElementById('settingDailyCount').value) || 2,
      generate_blog_images: document.getElementById('settingGenerateImages').checked,
      openai_text_model: document.getElementById('settingTextModel').value.trim(),
      openai_image_model: document.getElementById('settingImageModel').value.trim(),
      whatsapp_number: document.getElementById('settingWhatsapp').value.trim(),
      site_base_url: document.getElementById('settingBaseUrl').value.trim(),
      seo_brain_auto: document.getElementById('settingSeoBrainAuto').checked,
      seo_brain_runs_per_day: Number(document.getElementById('settingSeoRuns').value) || 2,
      gsc_site_url: document.getElementById('settingGscSiteUrl').value.trim()
    })
  });
  await refreshDashboard();
  window.alert('تم حفظ إعدادات SEO Brain.');
});

document.getElementById('rebuildBtn').addEventListener('click', async () => {
  await adminApi('/api/build', { method: 'POST', body: '{}' });
  await refreshDashboard();
  window.alert('تمت إعادة بناء الصفحات.');
});

async function generateNow(count, publishNow) {
  await adminApi('/api/blog/generate', { method: 'POST', body: JSON.stringify({ count, publish_now: publishNow }) });
  await refreshDashboard();
  window.alert(`تم تشغيل التوليد اليدوي لعدد ${count} مقال ${publishNow ? 'مع النشر الفوري' : 'كـ Draft'}.`);
}

document.getElementById('generateDraftNowBtn').addEventListener('click', async () => {
  await generateNow(Number(document.getElementById('manualGenerateCount').value) || 2, false);
});

document.getElementById('generateNowBtn').addEventListener('click', async () => {
  await generateNow(Number(document.getElementById('manualGenerateCount').value) || 2, true);
});

document.getElementById('generateArticleDraftBtn').addEventListener('click', async () => {
  await generateNow(2, false);
});

document.getElementById('generateArticleBtn').addEventListener('click', async () => {
  await generateNow(2, true);
});

document.getElementById('refreshArticlesBtn').addEventListener('click', refreshDashboard);

document.getElementById('saveArticleBtn').addEventListener('click', async () => {
  if (!state.selectedArticle) return;
  const payload = {
    ...state.selectedArticle,
    current_slug: articleFields.editingSlug.value.trim(),
    title_ar: articleFields.title.value.trim(),
    slug: articleFields.slug.value.trim(),
    meta_title: articleFields.metaTitle.value.trim(),
    meta_description: articleFields.metaDescription.value.trim(),
    excerpt: articleFields.excerpt.value.trim(),
    category: articleFields.category.value.trim(),
    tags: articleFields.tags.value.split(',').map((item) => item.trim()).filter(Boolean),
    content_markdown: articleFields.content.value
  };
  await adminApi('/api/blog/save', { method: 'POST', body: JSON.stringify(payload) });
  await refreshDashboard();
  window.alert('تم حفظ المقال.');
});

document.getElementById('toggleArticleStatusBtn').addEventListener('click', async () => {
  if (!state.selectedArticle?.slug) return;
  await adminApi('/api/blog/toggle-status', {
    method: 'POST',
    body: JSON.stringify({ slug: state.selectedArticle.slug })
  });
  await refreshDashboard();
});

document.getElementById('deleteArticleBtn').addEventListener('click', async () => {
  if (!state.selectedArticle?.slug) return;
  if (!window.confirm('تأكيد حذف المقال؟')) return;
  await adminApi('/api/blog/delete', {
    method: 'POST',
    body: JSON.stringify({ slug: state.selectedArticle.slug })
  });
  state.selectedArticle = null;
  await refreshDashboard();
});

renderTabs();
clearProductForm();
refreshDashboard().catch((error) => {
  document.getElementById('metrics').innerHTML = `<div class="metric"><span class="label">خطأ</span><strong>${escapeHTML(error.message)}</strong></div>`;
});
