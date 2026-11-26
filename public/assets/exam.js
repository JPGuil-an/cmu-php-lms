(function (root) {
    'use strict';

    // Only server timestamps and a monotonic clock control the countdown.
    // Changing the student's system date cannot expire an active server attempt.
    function createServerClock(serverNowMs, endsAtMs, monotonicNow) {
        let anchor = monotonicNow();
        let serverTime = Number(serverNowMs);
        let deadline = Number(endsAtMs);
        return {
            remainingMs() { return Math.max(0, deadline - serverTime - Math.max(0, monotonicNow() - anchor)); },
            sync(nowMs, endMs, transitMs) {
                serverTime = Number(nowMs) + Math.max(0, Number(transitMs) || 0);
                deadline = Number(endMs);
                anchor = monotonicNow();
            }
        };
    }

    if (typeof module === 'object' && module.exports) module.exports = { createServerClock };
    if (!root.document || !root.document.getElementById('examConfig')) return;

    const document = root.document;
    const config = JSON.parse(document.getElementById('examConfig').textContent);
    const questions = [...document.querySelectorAll('.question')];
    const numbers = [...document.querySelectorAll('.number')];
    const forms = questions.map(question => question.querySelector('.answerForm'));
    const token = document.querySelector('input[name="_csrf"]').value;
    const clock = createServerClock(config.serverNowMs, config.endsAtMs, () => root.performance.now());
    const timer = document.getElementById('timer');
    const message = document.getElementById('navMessage');
    const retry = document.getElementById('retrySave');
    const signIn = document.getElementById('resumeSignIn');
    let current = Math.min(config.currentIndex, questions.length - 1);
    let allowBack = config.allowBack, allowJump = config.allowJump;
    let cameraReady = !config.cameraRequired, cameraRequest = false;
    let closed = false, busy = false, timePending = false, summaryOpen = false;
    let stateRequest = null, retryTimer = null, saveChain = Promise.resolve();
    const acknowledged = new Map();
    const valueOf = form => {
        const selected = form.querySelector('input[name="answer"]:checked') || form.querySelector('input[name="answer"]:not([type="radio"])');
        return selected ? selected.value : '';
    };
    forms.forEach(form => acknowledged.set(form, valueOf(form)));

    function note(text, failed) { message.textContent = text; retry.hidden = !failed; }
    function blocked() { return closed || busy || timePending || (config.cameraRequired && !cameraReady); }
    function render() {
        questions.forEach((question, index) => { question.style.display = index === current ? 'block' : 'none'; });
        numbers.forEach((button, index) => {
            const restricted = !allowJump || (!allowBack && index < current);
            button.disabled = restricted || blocked();
            button.classList.toggle('teacher-restricted', restricted);
            button.classList.toggle('active', index === current);
            button.title = restricted ? 'This navigation is disabled by your teacher.' : 'Go to question ' + (index + 1);
        });
        const previous = document.getElementById('previous');
        previous.disabled = !allowBack || current === 0 || blocked();
        previous.classList.toggle('teacher-restricted', !allowBack);
        previous.title = !allowBack ? 'Returning to previous questions is disabled by your teacher.' : 'Previous question';
        const next = document.getElementById('next');
        next.disabled = blocked();
        next.textContent = busy ? 'Saving…' : current === questions.length - 1 ? (config.allowReview ? 'Review answers' : 'Finish exam') : 'Next';
        document.getElementById('progress').textContent = 'Question ' + (current + 1) + ' of ' + questions.length;
        document.getElementById('summary').style.display = summaryOpen ? 'block' : 'none';
        document.getElementById('answeredCount').textContent = forms.filter(form => valueOf(form).trim() !== '').length;
        forms.forEach(form => form.querySelectorAll('input[name="answer"]').forEach(input => { input.disabled = closed || timePending || busy; }));
        document.getElementById('submitExam').disabled = blocked();
    }

    async function api(route, body) {
        const controller = new AbortController();
        const timeout = root.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await root.fetch(route, {
                method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, body, signal: controller.signal
            });
            let result;
            try { result = await response.json(); } catch (_) { throw new Error('The server response could not be confirmed. Retry the connection.'); }
            if (!response.ok || !result.ok) {
                const error = new Error(result.message || 'The request was not saved. Retry the connection.');
                error.status = response.status; error.attemptClosed = result.attempt_closed;
                throw error;
            }
            return result;
        } finally { root.clearTimeout(timeout); }
    }

    function showError(error) {
        if (closed) return;
        note(error.name === 'AbortError' ? 'The connection timed out. Your last confirmed answers are saved. Retry before continuing.' : error.message || 'Connection interrupted. Retry before continuing.', true);
        if (error.status === 401) { signIn.hidden = false; retry.hidden = true; }
        if (error.attemptClosed) void syncState();
    }

    function finish(status) {
        if (closed || status === 'in_progress') return;
        closed = true; timePending = false;
        root.clearInterval(ticker); root.clearInterval(poller); root.clearInterval(cameraWatcher);
        root.clearTimeout(retryTimer);
        document.getElementById('camera').srcObject?.getTracks().forEach(track => track.stop());
        render();
        const heading = document.createElement('h2');
        heading.textContent = status === 'reset' ? 'Your teacher reset this attempt.' : status === 'auto_submitted' ? 'Your attempt was automatically submitted.' : 'Your attempt has been submitted.';
        const description = document.createElement('p');
        description.textContent = 'Your saved answers are retained. This attempt is no longer editable.';
        const link = document.createElement('a'); link.href = '?route=dashboard'; link.className = 'btn'; link.textContent = 'Back to dashboard';
        const card = document.createElement('section'); card.className = 'exam-card'; card.append(heading, description, link);
        document.querySelector('.exam-main').replaceChildren(card);
        timer.textContent = status === 'reset' ? 'Attempt reset' : 'Submitted';
        note('Saved answers are locked.', false);
    }

    async function syncState() {
        if (closed) return;
        if (stateRequest) return stateRequest;
        stateRequest = (async () => {
            const began = root.performance.now();
            try {
                const state = await api('?route=exam-state&attempt=' + config.attemptId);
                clock.sync(state.server_now_ms, state.ends_at_ms, (root.performance.now() - began) / 2);
                allowBack = state.allow_back_navigation; allowJump = state.allow_question_jump;
                if (state.status !== 'in_progress') { finish(state.status); return; }
                const resumed = timePending && clock.remainingMs() > 0;
                timePending = clock.remainingMs() <= 0;
                if (timePending && !retryTimer) retryTimer = root.setTimeout(() => { retryTimer = null; void syncState(); }, 2000);
                if (resumed) note('Timer synchronized with the server. You can continue.', false);
                render();
            } catch (error) {
                showError(error);
                if (timePending && error.status !== 401 && !closed && !retryTimer) {
                    retryTimer = root.setTimeout(() => { retryTimer = null; void syncState(); }, 15000);
                }
            } finally { stateRequest = null; }
        })();
        return stateRequest;
    }

    function tick() {
        if (closed) return;
        const remaining = clock.remainingMs();
        const seconds = Math.ceil(remaining / 1000);
        timer.textContent = 'Time remaining: ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        if (remaining <= 0 && !timePending) {
            timePending = true; note('Time limit reached. Confirming submission with the server…', false); render();
            void syncState();
        }
    }

    function saveAnswer(form) {
        const answer = valueOf(form);
        const send = saveChain.then(async () => {
            if (closed || timePending) throw new Error('This attempt is ending. Waiting for server confirmation.');
            if (acknowledged.get(form) === answer) return;
            note('Saving answer…', false);
            const body = new URLSearchParams({ _csrf: token, attempt_id: String(config.attemptId), question_id: form.querySelector('[name="question_id"]').value, answer });
            await api(form.getAttribute('action'), body);
            acknowledged.set(form, answer);
            const item = document.querySelector('[data-summary="' + form.closest('.question').dataset.index + '"] span');
            const selected = form.querySelector('input[name="answer"]:checked');
            if (item) item.textContent = selected ? selected.closest('label').textContent.trim() : answer.trim() || 'Not answered';
            note('Answer saved.', false);
            if (!closed) render();
        });
        saveChain = send.catch(() => {});
        return send;
    }

    async function flushCurrent() { await saveChain; await saveAnswer(forms[current]); }
    async function go(index) {
        if (blocked() || index < 0 || index >= questions.length || (!allowBack && index < current)) return;
        busy = true; render();
        try {
            await flushCurrent();
            await api('?route=exam-position', new URLSearchParams({ _csrf: token, attempt_id: String(config.attemptId), position: String(index) }));
            current = index; summaryOpen = false; note('Progress saved.', false);
        } catch (error) { showError(error); }
        finally { busy = false; if (!closed) render(); }
    }

    forms.forEach(form => {
        form.addEventListener('submit', event => { event.preventDefault(); if (!closed && !timePending) void saveAnswer(form).catch(showError); });
        form.addEventListener('change', () => { if (!closed && !timePending) void saveAnswer(form).catch(showError); });
        form.querySelectorAll('input[name="answer"]:not([type="radio"])').forEach(input => input.addEventListener('blur', () => {
            if (!closed && !timePending && acknowledged.get(form) !== valueOf(form)) void saveAnswer(form).catch(showError);
        }));
    });
    document.getElementById('previous').onclick = () => void go(current - 1);
    document.getElementById('next').onclick = async () => {
        if (current !== questions.length - 1) { await go(current + 1); return; }
        if (blocked()) return;
        busy = true; render();
        try { await flushCurrent(); summaryOpen = true; }
        catch (error) { showError(error); }
        finally { busy = false; if (!closed) { render(); if (summaryOpen) document.getElementById('summary').scrollIntoView({ behavior: 'smooth' }); } }
    };
    numbers.forEach(button => { button.onclick = () => { if (allowJump) void go(Number(button.dataset.index)); }; });
    retry.onclick = async () => { await syncState(); if (!closed && !timePending) try { await flushCurrent(); note('Connection restored. Progress is saved.', false); } catch (error) { showError(error); } };
    document.getElementById('finalSubmissionForm').addEventListener('submit', async event => {
        event.preventDefault(); if (blocked() || !root.confirm('Submit your exam now? Your answers will be final.')) return;
        busy = true; render();
        try { await flushCurrent(); event.target.submit(); }
        catch (error) { busy = false; showError(error); if (!closed) render(); }
    });
    document.querySelectorAll('.flagQuestion').forEach(button => button.addEventListener('click', async () => {
        if (blocked()) return;
        try { const data = await api('?route=exam-flag', new URLSearchParams({ _csrf: token, attempt_id: String(config.attemptId), question_id: button.dataset.questionId })); button.textContent = data.flagged ? 'Unflag question' : 'Flag question'; }
        catch (error) { showError(error); }
    }));

    async function requestCamera() {
        if (!config.cameraRequired || closed || cameraRequest) return;
        cameraRequest = true;
        try {
            if (!root.navigator.mediaDevices?.getUserMedia) throw new Error('Camera access is unavailable.');
            const stream = await root.navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            if (closed) { stream.getTracks().forEach(track => track.stop()); return; }
            document.getElementById('camera').srcObject = stream; cameraReady = true;
            document.getElementById('cameraStatus').textContent = 'Monitoring — camera active'; document.getElementById('retryCamera').hidden = true;
        } catch (_) { cameraReady = false; document.getElementById('cameraStatus').textContent = 'Allow camera access to continue.'; document.getElementById('retryCamera').hidden = false; }
        finally { cameraRequest = false; if (!closed) render(); }
    }
    document.getElementById('retryCamera').onclick = () => void requestCamera();
    function reportEvent(kind, metadata) {
        if (closed) return;
        void api('?route=exam-event', new URLSearchParams({ _csrf: token, attempt_id: String(config.attemptId), kind, metadata: JSON.stringify(metadata || {}) }))
            .then(state => { if (state.status !== 'in_progress') finish(state.status); }).catch(error => { if (error.status === 401) showError(error); });
    }
    document.addEventListener('visibilitychange', () => { reportEvent(document.hidden ? 'visibility_hidden' : 'focus_return'); if (!document.hidden) void syncState(); });
    root.addEventListener('blur', () => reportEvent('tab_blur'));
    root.addEventListener('focus', () => { reportEvent('focus_return'); void syncState(); });
    root.addEventListener('online', () => { if (!closed) void syncState(); });
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) reportEvent('fullscreen_exit'); });
    ['copy', 'cut', 'paste', 'contextmenu'].forEach(name => document.addEventListener(name, event => { if (!closed) { event.preventDefault(); reportEvent('copy_attempt', { event: name }); } }));
    const cameraWatcher = root.setInterval(() => {
        if (!config.cameraRequired || closed || cameraRequest) return;
        const active = !!document.getElementById('camera').srcObject?.getVideoTracks().some(track => track.readyState === 'live');
        if (active !== cameraReady) { cameraReady = active; reportEvent(active ? 'camera_restored' : 'camera_lost'); document.getElementById('cameraStatus').textContent = active ? 'Monitoring — camera active' : 'Allow camera access to continue.'; document.getElementById('retryCamera').hidden = active; render(); }
    }, 1000);
    const ticker = root.setInterval(tick, 1000);
    const poller = root.setInterval(() => { if (!document.hidden) void syncState(); }, 30000);
    render(); tick(); void syncState(); void requestCamera();
})(typeof window !== 'undefined' ? window : globalThis);
