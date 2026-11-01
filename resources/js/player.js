import videojs from 'video.js';
import fa from 'video.js/dist/lang/fa.json';
import 'video.js/dist/video-js.css';
import '../css/player.css';

videojs.addLanguage('fa', fa);

const store = {
    get(key) {
        try { return localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
        try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
    },
    remove(key) {
        try { localStorage.removeItem(key); } catch { /* storage unavailable */ }
    },
};

// Video.js track lists and cue lists are array-like but not iterable.
const toArray = (list) => Array.prototype.slice.call(list || []);

const dataEl = document.getElementById('watchData');
const listEl = document.getElementById('episodeList');
const tabsEl = document.getElementById('seasonTabs');

if (dataEl && listEl) {
    init(JSON.parse(dataEl.textContent));
}

function init(data) {
    const player = videojs('mainPlayer', {
        language: 'fa',
        fluid: true,
        playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2],
        userActions: { hotkeys: true },
        html5: { nativeTextTracks: false },
        controlBar: { pictureInPictureToggle: true },
    });

    let seasonIndex = 0;
    let current = null;
    let offset = 0;
    let swapping = false;
    let lastSaved = 0;
    const applied = new WeakMap();

    // ---------- subtitle delay ----------
    const Button = videojs.getComponent('Button');
    class SubDelay extends Button {
        handleClick() {
            shift(this.options_.delta);
        }
    }
    videojs.registerComponent('SubDelay', SubDelay);

    const bar = player.getChild('controlBar');
    const before = bar.children().indexOf(bar.getChild('fullscreenToggle'));
    [[0.5, '+', 'تأخیر زیرنویس (۰٫۵ ثانیه دیرتر)'], [-0.5, '−', 'تأخیر زیرنویس (۰٫۵ ثانیه زودتر)']].forEach(([delta, label, title], i) => {
        const btn = bar.addChild('SubDelay', { delta }, before + i);
        btn.addClass('vjs-sub-delay');
        btn.el().textContent = label;
        btn.el().setAttribute('title', title);
        btn.el().setAttribute('aria-label', title);
    });

    const badge = document.createElement('div');
    badge.className = 'sub-offset-badge';
    player.el().appendChild(badge);
    let badgeTimer;

    function shiftTrack(track) {
        const delta = offset - (applied.get(track) || 0);
        if (!delta || !track.cues || !track.cues.length) return;
        for (const cue of toArray(track.cues)) {
            cue.startTime += delta;
            cue.endTime += delta;
        }
        applied.set(track, offset);
    }

    function shiftAll() {
        toArray(player.textTracks()).forEach(shiftTrack);
    }

    function shift(delta) {
        offset = Math.round((offset + delta) * 10) / 10;
        shiftAll();
        badge.textContent = `زیرنویس ${offset > 0 ? '+' : ''}${offset.toFixed(1)}s`;
        badge.classList.add('show');
        clearTimeout(badgeTimer);
        badgeTimer = setTimeout(() => badge.classList.remove('show'), 1800);
    }

    player.textTracks().addEventListener('change', () => {
        shiftAll();
        if (swapping) return;
        const showing = toArray(player.textTracks()).find((t) => t.mode === 'showing' && t.kind === 'subtitles');
        store.set('sublang', showing ? showing.language : 'off');
    });

    // ---------- playback ----------
    function play(video, autoplay = true) {
        current = video;
        offset = 0;
        swapping = true;

        toArray(player.remoteTextTracks()).forEach((t) => player.removeRemoteTextTrack(t));
        player.src({ src: video.url, type: video.type });

        const pref = store.get('sublang');
        video.subtitles.forEach((s) => {
            const el = player.addRemoteTextTrack({
                kind: 'subtitles',
                src: s.url,
                srclang: s.lang,
                label: s.label,
                default: s.lang === pref,
            }, false);
            el.addEventListener('load', () => shiftTrack(el.track));
        });
        setTimeout(() => { swapping = false; }, 1500);

        player.one('loadedmetadata', () => {
            const saved = parseFloat(store.get(`vpos:${video.path}`));
            if (saved > 15 && saved < player.duration() - 30) player.currentTime(saved);
        });

        if (autoplay) {
            const started = player.play();
            if (started) started.catch(() => { /* autoplay blocked: user presses play */ });
        }

        listEl.querySelectorAll('li').forEach((li) => li.classList.toggle('active', li.dataset.path === video.path));
    }

    player.on('timeupdate', () => {
        if (!current) return;
        const t = player.currentTime();
        if (Math.abs(t - lastSaved) >= 5) {
            lastSaved = t;
            store.set(`vpos:${current.path}`, String(Math.floor(t)));
        }
    });

    player.on('ended', () => {
        if (!current) return;
        store.remove(`vpos:${current.path}`);
        const next = nextVideo();
        if (next) {
            if (next.season !== seasonIndex) selectSeason(next.season, false);
            play(next.video);
        }
    });

    function nextVideo() {
        const videos = data.seasons[seasonIndex].videos;
        const i = videos.findIndex((v) => v.path === current.path);
        if (i >= 0 && i + 1 < videos.length) return { season: seasonIndex, video: videos[i + 1] };
        const nextSeason = data.seasons[seasonIndex + 1];
        if (nextSeason && nextSeason.videos.length) return { season: seasonIndex + 1, video: nextSeason.videos[0] };
        return null;
    }

    // ---------- lists (DOM only: file names are never parsed as HTML) ----------
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function iconButton(tag, className, icon, title) {
        const node = el(tag, `btn btn-sm ${className}`);
        node.append(el('i', `fas fa-${icon}`));
        node.title = title;
        return node;
    }

    function renderList() {
        listEl.replaceChildren(...data.seasons[seasonIndex].videos.map((video) => {
            const li = el('li', 'list-group-item d-flex justify-content-between align-items-center flex-row-reverse');
            li.dataset.path = video.path;
            if (current && current.path === video.path) li.classList.add('active');

            const icons = el('span', 'file-icons');
            const download = iconButton('a', 'btn-outline-success ms-2', 'download', 'دانلود');
            download.href = video.download || video.url;
            download.setAttribute('download', '');
            icons.append(download);

            if (data.admin) {
                const subs = iconButton('button', 'btn-outline-warning ms-2', 'closed-captioning', 'زیرنویس');
                subs.type = 'button';
                subs.addEventListener('click', () => openSubtitleModal(video));
                icons.append(subs);
            }

            const playBtn = iconButton('button', 'btn-outline-primary play-btn', 'play', 'تماشا');
            playBtn.type = 'button';
            playBtn.addEventListener('click', () => play(video));
            icons.append(playBtn);

            const name = el('span', 'file-name');
            name.append(video.name);
            video.subtitles.forEach((s) => name.append(' ', el('span', 'badge bg-secondary', s.lang.toUpperCase())));

            li.append(icons, name);
            return li;
        }));
    }

    function selectSeason(index, scroll = true) {
        seasonIndex = index;
        tabsEl.querySelectorAll('.nav-link').forEach((t, i) => t.classList.toggle('active', i === index));
        renderList();
        if (scroll) listEl.scrollTop = 0;
    }

    if (data.seasons.length > 1 || data.seasons[0].name) {
        data.seasons.forEach((season, i) => {
            const item = el('li', 'nav-item');
            const tab = el('button', 'nav-link', season.name);
            tab.type = 'button';
            tab.addEventListener('click', () => selectSeason(i));
            item.append(tab);
            tabsEl.append(item);
        });
    } else {
        tabsEl.remove();
    }
    selectSeason(0);

    // ---------- admin: online subtitles (SubSource) ----------
    let modalVideo = null;
    const online = document.getElementById('onlineSubtitles');

    async function onlineCall(method, url, body) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const json = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(json.message || `خطا (${response.status})`);
        return json;
    }

    function setOnlineStatus(text, isError = false) {
        const box = document.getElementById('onlineStatus');
        box.textContent = text;
        box.className = `small mb-2 ${isError ? 'text-danger' : 'text-muted'}`;
    }

    async function fetchSubtitle(subtitleId) {
        setOnlineStatus('در حال دریافت...');
        try {
            const result = await onlineCall('POST', online.dataset.fetchUrl, { video: modalVideo.path, subtitle_id: subtitleId });
            if (result.status === 'stored' || result.status === 'exists') {
                setOnlineStatus(result.status === 'stored' ? 'زیرنویس ذخیره شد؛ بارگذاری مجدد...' : 'زیرنویس از قبل وجود دارد.');
                setTimeout(() => window.location.reload(), 900);
            } else {
                setOnlineStatus('زیرنویس مناسبی پیدا نشد. این نتیجه ذخیره شد و تا چند روز دوباره جستجو نمی‌شود.', true);
            }
        } catch (error) {
            setOnlineStatus(error.message, true);
        }
    }

    function renderCandidates(candidates) {
        document.getElementById('onlineList').replaceChildren(...candidates.slice(0, 12).map((c) => {
            const row = el('div', 'list-group-item d-flex justify-content-between align-items-center gap-2');
            const info = el('div', 'small');
            info.append(el('div', 'fw-semibold', c.release || '—'));
            const meta = [`👍 ${c.good}/${c.total}`, `${c.downloads} دانلود`, c.files > 1 ? `${c.files} فایل` : null, c.hearing_impaired ? 'ناشنوایان' : null].filter(Boolean);
            info.append(el('div', 'text-muted', meta.join(' · ')));
            const pick = iconButton('button', 'btn-outline-success', 'download', 'دریافت این زیرنویس');
            pick.type = 'button';
            pick.addEventListener('click', () => fetchSubtitle(c.id));
            row.append(info, pick);
            return row;
        }));
    }

    if (online) {
        document.getElementById('onlineSearch').addEventListener('click', async () => {
            setOnlineStatus('در حال جستجو...');
            document.getElementById('onlineList').replaceChildren();
            try {
                const result = await onlineCall('GET', `${online.dataset.onlineUrl}?video=${encodeURIComponent(modalVideo.path)}`);
                if (result.local) return setOnlineStatus('برای این ویدیو از قبل زیرنویس فارسی هست.');
                if (!result.candidates.length) return setOnlineStatus(result.message || 'نتیجه‌ای پیدا نشد.', true);
                setOnlineStatus(`${result.candidates.length} نتیجه (بهترین‌ها بالا). یکی را انتخاب کنید:`);
                renderCandidates(result.candidates);
            } catch (error) {
                setOnlineStatus(error.message, true);
            }
        });
        document.getElementById('onlineAuto').addEventListener('click', () => fetchSubtitle(null));
    }

    // ---------- admin: subtitle modal ----------
    function openSubtitleModal(video) {
        modalVideo = video;
        if (online) {
            setOnlineStatus('');
            document.getElementById('onlineList').replaceChildren();
        }
        const modalEl = document.getElementById('subtitleModal');
        modalEl.querySelector('#subtitleModalTitle').textContent = video.name;
        modalEl.querySelector('#subtitleVideo').value = video.path;

        const existing = modalEl.querySelector('#subtitleExisting');
        existing.replaceChildren(...video.subtitles.map((s) => {
            const form = el('form', 'd-flex align-items-center gap-2 mb-1');
            form.method = 'POST';
            form.action = document.getElementById('subtitleDeleteUrl')?.value || '/subtitles';
            [['_token', document.querySelector('meta[name="csrf-token"]').content], ['_method', 'DELETE'], ['path', s.path]].forEach(([n, v]) => {
                const input = el('input');
                input.type = 'hidden';
                input.name = n;
                input.value = v;
                form.append(input);
            });
            form.addEventListener('submit', (e) => {
                if (!window.confirm('این زیرنویس حذف شود؟')) e.preventDefault();
            });
            const del = iconButton('button', 'btn-outline-danger', 'trash', 'حذف');
            del.type = 'submit';
            form.append(del, el('span', null, s.label), el('small', 'text-muted', s.path.split('/').pop()));
            return form;
        }));

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}
