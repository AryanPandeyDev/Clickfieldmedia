// The /hiring application form. Two steps on one page:
//   1. The details and CV go to api/apply.php (send_code), which emails a code.
//   2. The applicant types the code (verify) and the application is sent.
// The form stays on screen throughout, so nothing typed is lost.
//
// A verified application goes to /hiring/thank-you/, which fires its own events
// (GA4 apply_submitted, Meta JobApplication) rather than the enquiry form's
// generate_lead and Lead, so job applicants are not counted as sales leads.

const INBOX = 'clickfieldmedia@gmail.com';
const CV_MAX_BYTES = 5 * 1024 * 1024;
const CV_TYPES = ['pdf', 'doc', 'docx'];

type ApiResult = { ok: boolean; error?: string; field?: string | null; token?: string; resend_after?: number; name?: string };

async function post(url: string, body: FormData, timeoutMs: number): Promise<{ response: Response; result: ApiResult }> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
    if (!response.headers.get('content-type')?.includes('application/json')) {
      throw new Error(response.status === 413 ? 'Your CV is larger than 5 MB. Please upload a smaller file.' : `Something went wrong on our side. Please try again, or email your CV to ${INBOX}.`);
    }
    return { response, result: await response.json() };
  } catch (error) {
    if (error instanceof Error && error.name !== 'AbortError' && error.message !== 'Failed to fetch') throw error;
    throw new Error(`The connection dropped or timed out. Please check your internet and try again, or email your CV to ${INBOX}.`);
  } finally {
    clearTimeout(timer);
  }
}

export function initApplyForm() {
  const form = document.querySelector<HTMLFormElement>('[data-apply-form]');
  if (!form) return;

  const detailsStep = form.querySelector<HTMLElement>('[data-apply-step="details"]')!;
  const codeStep = form.querySelector<HTMLElement>('[data-apply-step="code"]')!;
  const fieldsets = [...form.querySelectorAll<HTMLFieldSetElement>('.hiring-fieldset')];
  const detailsMessage = form.querySelector<HTMLElement>('[data-form-message]')!;
  const detailsButton = detailsStep.querySelector<HTMLButtonElement>('[type=submit]')!;
  const detailsText = form.querySelector<HTMLElement>('[data-submit-text]')!;
  const codeInput = form.querySelector<HTMLInputElement>('[data-code-input]')!;
  const codeMessage = form.querySelector<HTMLElement>('[data-code-message]')!;
  const codeButton = codeStep.querySelector<HTMLButtonElement>('[type=submit]')!;
  const codeText = form.querySelector<HTMLElement>('[data-verify-text]')!;
  const codeTitle = form.querySelector<HTMLElement>('[data-verify-title]')!;
  const emailOut = form.querySelector<HTMLElement>('[data-verify-email]')!;
  const resendButton = form.querySelector<HTMLButtonElement>('[data-resend]')!;
  const changeButton = form.querySelector<HTMLButtonElement>('[data-change-details]')!;

  const uploadBox = form.querySelector<HTMLElement>('[data-upload]')!;
  const cvInput = form.querySelector<HTMLInputElement>('[data-upload-input]')!;
  const cvName = form.querySelector<HTMLElement>('[data-upload-name]')!;

  let token = '';
  let busy = false;
  let countdown = 0;
  let countdownTimer: number | undefined;

  const show = (el: HTMLElement, text: string, state: 'error' | 'success') => {
    el.textContent = text;
    el.dataset.state = state;
    el.hidden = false;
  };

  const markInvalid = (name: string | null | undefined) => {
    if (!name || !/^[a-z_]+$/.test(name)) return;
    if (name === 'cv') {
      uploadBox.setAttribute('aria-invalid', 'true');
      cvInput.focus();
      return;
    }
    const field = form.querySelector<HTMLInputElement>(`[name="${name}"], [name="${name}[]"]`);
    field?.setAttribute('aria-invalid', 'true');
    field?.focus();
  };

  // --- CV picker -------------------------------------------------------------

  cvInput.addEventListener('change', () => {
    const file = cvInput.files?.[0];
    uploadBox.removeAttribute('aria-invalid');
    cvInput.setCustomValidity('');
    if (file) {
      const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
      if (!CV_TYPES.includes(ext)) cvInput.setCustomValidity('Please upload your CV as a PDF or Word file.');
      else if (file.size > CV_MAX_BYTES) cvInput.setCustomValidity('Your CV is larger than 5 MB. Please upload a smaller file.');
    }
    const problem = cvInput.validationMessage && file ? cvInput.validationMessage : '';
    cvName.textContent = file ? file.name : 'Choose a file';
    uploadBox.classList.toggle('has-file', Boolean(file) && !problem);
    if (problem) {
      uploadBox.setAttribute('aria-invalid', 'true');
      cvInput.reportValidity();
    }
  });

  form.addEventListener('input', event => {
    const target = event.target as HTMLElement;
    target.removeAttribute('aria-invalid');
    if (target === codeInput) codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
  });

  // --- Tick-lists ------------------------------------------------------------
  // A tick-list needs at least one answer, which the browser can only ask for
  // one box at a time. So the whole group's answer hangs off its first box,
  // and is cleared the moment any box in the group is ticked.
  const tickGroups = new Map<string, HTMLInputElement[]>();
  form.querySelectorAll<HTMLInputElement>('input[type=checkbox][data-tick-group]').forEach(box => {
    tickGroups.set(box.name, [...(tickGroups.get(box.name) ?? []), box]);
  });
  tickGroups.forEach(boxes => {
    const sync = () => {
      const answered = boxes.some(box => box.checked);
      boxes[0].setCustomValidity(answered ? '' : 'Please tick at least one of these.');
    };
    boxes.forEach(box => box.addEventListener('change', sync));
    sync();
  });

  // --- Steps -----------------------------------------------------------------

  const startCountdown = (seconds: number) => {
    countdown = seconds;
    clearInterval(countdownTimer);
    const tick = () => {
      resendButton.disabled = countdown > 0;
      resendButton.textContent = countdown > 0 ? `Resend code in ${countdown}s` : 'Resend code';
      if (countdown <= 0) clearInterval(countdownTimer);
      countdown--;
    };
    tick();
    countdownTimer = window.setInterval(tick, 1000);
  };

  const toCodeStep = (email: string, resendAfter: number) => {
    fieldsets.forEach(fieldset => { fieldset.disabled = true; });
    detailsStep.hidden = true;
    codeStep.hidden = false;
    codeInput.disabled = false;
    codeInput.value = '';
    codeMessage.hidden = true;
    emailOut.textContent = email;
    startCountdown(resendAfter);
    codeTitle.focus({ preventScroll: true });
    codeStep.scrollIntoView({ block: 'center' });
    codeInput.focus({ preventScroll: true });
  };

  const toDetailsStep = () => {
    token = '';
    clearInterval(countdownTimer);
    fieldsets.forEach(fieldset => { fieldset.disabled = false; });
    codeInput.disabled = true;
    codeStep.hidden = true;
    detailsStep.hidden = false;
    detailsMessage.hidden = true;
  };

  changeButton.addEventListener('click', () => {
    toDetailsStep();
    form.querySelector<HTMLInputElement>('[name="email"]')?.focus();
  });

  // Step 1: details and CV.
  const sendCode = async () => {
    if (!form.reportValidity()) return;
    busy = true;
    detailsButton.disabled = true;
    form.setAttribute('aria-busy', 'true');
    detailsText.textContent = 'Uploading and sending code…';
    detailsMessage.hidden = true;
    try {
      const body = new FormData(form);
      body.set('action', 'send_code');
      const { response, result } = await post(form.action, body, 90000);
      if (!response.ok || !result.ok || !result.token) {
        markInvalid(result.field);
        throw new Error(result.error || `Your application could not be sent. Please try again, or email your CV to ${INBOX}.`);
      }
      token = result.token;
      toCodeStep(String(body.get('email') ?? ''), result.resend_after ?? 45);
    } catch (error) {
      show(detailsMessage, (error as Error).message, 'error');
      if (!form.querySelector('[aria-invalid=true]')) detailsMessage.focus();
    } finally {
      busy = false;
      detailsButton.disabled = false;
      form.removeAttribute('aria-busy');
      detailsText.textContent = 'Verify email and continue';
    }
  };

  // Step 2: the code.
  const verify = async () => {
    if (!/^\d{6}$/.test(codeInput.value)) {
      codeInput.setAttribute('aria-invalid', 'true');
      show(codeMessage, 'Please enter the 6-digit code from the email.', 'error');
      codeInput.focus();
      return;
    }
    busy = true;
    codeButton.disabled = true;
    form.setAttribute('aria-busy', 'true');
    codeText.textContent = 'Sending application…';
    codeMessage.hidden = true;
    try {
      const body = new FormData();
      body.set('action', 'verify');
      body.set('token', token);
      body.set('code', codeInput.value);
      const { response, result } = await post(form.action, body, 60000);
      // A double tap that already went through.
      if (response.status === 409) result.ok = true;
      if (!result.ok) {
        // The pending application itself is gone, so a new code needs the form again.
        if (response.status === 410 && result.field !== 'code') {
          toDetailsStep();
          show(detailsMessage, result.error || 'This application expired. Please press “Verify email and continue” again.', 'error');
          detailsMessage.focus();
          return;
        }
        if (result.field === 'code') codeInput.setAttribute('aria-invalid', 'true');
        throw new Error(result.error || `Your application could not be sent. Please try again, or email your CV to ${INBOX}.`);
      }
      clearInterval(countdownTimer);
      const role = form.querySelector<HTMLInputElement>('[name=role]')?.value ?? '';
      try {
        sessionStorage.setItem('cf-applied', JSON.stringify({ name: (result.name ?? '').split(' ')[0], role }));
      } catch {}
      window.location.href = '/hiring/thank-you/';
      return;
    } catch (error) {
      show(codeMessage, (error as Error).message, 'error');
      codeInput.focus();
    } finally {
      busy = false;
      codeButton.disabled = false;
      form.removeAttribute('aria-busy');
      codeText.textContent = 'Confirm and send application';
    }
  };

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (busy) return;
    if (token) verify();
    else sendCode();
  });

  resendButton.addEventListener('click', async () => {
    if (busy || !token) return;
    busy = true;
    resendButton.disabled = true;
    resendButton.textContent = 'Sending…';
    codeMessage.hidden = true;
    try {
      const body = new FormData();
      body.set('action', 'resend');
      body.set('token', token);
      const { response, result } = await post(form.action, body, 30000);
      if (!response.ok || !result.ok) {
        if (response.status === 410) {
          toDetailsStep();
          show(detailsMessage, result.error || 'This application expired. Please press “Verify email and continue” again.', 'error');
          detailsMessage.focus();
          return;
        }
        throw new Error(result.error || 'We couldn’t send a new code. Please try again.');
      }
      codeInput.value = '';
      show(codeMessage, 'A new code is on its way. Codes from earlier emails no longer work.', 'success');
      startCountdown(result.resend_after ?? 45);
      codeInput.focus();
    } catch (error) {
      show(codeMessage, (error as Error).message, 'error');
      resendButton.disabled = false;
      resendButton.textContent = 'Resend code';
    } finally {
      busy = false;
    }
  });
}
