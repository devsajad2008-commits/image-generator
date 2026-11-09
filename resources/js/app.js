const wizard = document.querySelector('[data-wizard]');

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm || 'از انجام این عملیات مطمئن هستید؟')) {
            event.preventDefault();
        }
    });
});

if (wizard instanceof HTMLFormElement) {
    const steps = [...wizard.querySelectorAll('[data-wizard-step]')];
    const indicators = [...wizard.querySelectorAll('[data-step-indicator]')];
    const nextButton = wizard.querySelector('[data-wizard-next]');
    const backButton = wizard.querySelector('[data-wizard-back]');
    const submitButton = wizard.querySelector('[data-wizard-submit]');
    const hint = wizard.querySelector('[data-wizard-hint]');
    const modeInputs = [...wizard.querySelectorAll('input[name="input_mode"]')];
    const modePanels = [...wizard.querySelectorAll('[data-mode-panel]')];
    const productName = wizard.querySelector('#product_name');
    const productNames = wizard.querySelector('#product_names');
    const productsFile = wizard.querySelector('#products_file');
    const imageCount = wizard.querySelector('#images_per_product');
    const errorMessages = [...wizard.querySelectorAll('[data-wizard-error]')];
    const reviewMode = wizard.querySelector('[data-review-mode]');
    const reviewProducts = wizard.querySelector('[data-review-products]');
    const reviewCount = wizard.querySelector('[data-review-count]');
    const fileName = wizard.querySelector('[data-file-name]');
    const modeLabels = { single: 'یک محصول', bulk: 'فهرست محصولات', file: 'فایل JSON' };
    const hints = [
        'اطلاعات محصولت را وارد کن تا ادامه بدهیم.',
        'تعداد تصویرها را برای هر محصول مشخص کن.',
        'خلاصه را بررسی کن و جست‌وجو را شروع کن.',
    ];
    let activeStep = 0;

    wizard.classList.add('wizard-ready');

    const selectedMode = () => modeInputs.find((input) => input.checked)?.value ?? 'single';

    const updateMode = () => {
        const mode = selectedMode();

        modeInputs.forEach((input) => {
            input.closest('.mode-option')?.classList.toggle('is-selected', input.checked);
        });
        modePanels.forEach((panel) => {
            const active = panel.dataset.modePanel === mode;
            panel.hidden = !active;
            panel.querySelectorAll('input, textarea').forEach((input) => {
                input.disabled = !active;
            });
        });
        updateReview();
    };

    const updateReview = () => {
        const mode = selectedMode();
        let products = '—';

        if (mode === 'single') {
            products = productName.value.trim() || 'نام محصول وارد نشده';
        } else if (mode === 'bulk') {
            const count = productNames.value.split(/\r?\n/u).filter((name) => name.trim()).length;
            products = count ? `${count} محصول` : 'فهرست وارد نشده';
        } else {
            products = productsFile.files[0]?.name || 'فایلی انتخاب نشده';
        }

        reviewMode.textContent = modeLabels[mode];
        reviewProducts.textContent = products;
        reviewCount.textContent = imageCount.value || '—';
    };

    const showStep = (index) => {
        activeStep = index;
        steps.forEach((step, stepIndex) => {
            step.classList.toggle('is-current', stepIndex === index);
            step.setAttribute('aria-hidden', String(stepIndex !== index));
        });
        indicators.forEach((indicator, stepIndex) => {
            indicator.classList.toggle('is-active', stepIndex === index);
            indicator.classList.toggle('is-complete', stepIndex < index);
            if (stepIndex < index) {
                indicator.setAttribute('aria-label', `مرحلهٔ ${stepIndex + 1}، انجام‌شده`);
            } else {
                indicator.removeAttribute('aria-label');
            }
        });
        backButton.hidden = index === 0;
        nextButton.hidden = index === steps.length - 1;
        submitButton.hidden = index !== steps.length - 1;
        hint.textContent = hints[index];
        errorMessages.forEach((message) => {
            message.hidden = true;
            message.textContent = '';
        });
        if (index === steps.length - 1) {
            updateReview();
        }
    };

    const validateStep = () => {
        let message = '';

        if (activeStep === 0) {
            const mode = selectedMode();
            if (mode === 'single' && !productName.value.trim()) {
                message = 'نام محصول را وارد کن.';
                productName.focus();
            } else if (mode === 'bulk' && !productNames.value.split(/\r?\n/u).some((name) => name.trim())) {
                message = 'حداقل نام یک محصول را وارد کن.';
                productNames.focus();
            } else if (mode === 'file' && !productsFile.files.length) {
                message = 'فایل JSON محصولات را انتخاب کن.';
                productsFile.focus();
            }
        } else if (activeStep === 1) {
            const count = Number(imageCount.value);
            if (!Number.isInteger(count) || count < 1 || count > 10) {
                message = 'تعداد تصویر باید عددی بین ۱ تا ۱۰ باشد.';
                imageCount.focus();
            }
        }

        if (message) {
            const error = errorMessages[activeStep];
            error.textContent = message;
            error.hidden = false;
            return false;
        }

        return true;
    };

    modeInputs.forEach((input) => input.addEventListener('change', updateMode));
    [productName, productNames, imageCount].forEach((input) => {
        input.addEventListener('input', updateReview);
    });
    productsFile.addEventListener('change', () => {
        fileName.textContent = productsFile.files[0]?.name || 'هنوز فایلی انتخاب نشده';
        updateReview();
    });
    nextButton.addEventListener('click', () => {
        if (validateStep()) {
            showStep(Math.min(activeStep + 1, steps.length - 1));
        }
    });
    backButton.addEventListener('click', () => showStep(Math.max(activeStep - 1, 0)));
    wizard.addEventListener('submit', (event) => {
        if (!validateStep()) {
            event.preventDefault();
        }
    });

    updateMode();
    showStep(0);
}
