(function () {
	'use strict'

	function init() {
		const root = document.getElementById('timetrackercards')
		if (!root) {
			return
		}
		const $ = (selector, context = root) => context.querySelector(selector)

		const grid = $('#ttc-grid')
		const emptyEl = $('#ttc-empty')
		const errorEl = $('#ttc-error')
		const template = $('#ttc-card-tpl')
		const i18n = $('#ttc-i18n').dataset
		const dlgEdit = $('#ttc-dlg-edit')
		const dlgShare = $('#ttc-dlg-share')
		const api = root.dataset.api
		const usersUrl = root.dataset.usersUrl
		const avatarTemplate = root.dataset.avatarUrl || ''
		const me = root.dataset.uid || ''
		const baseTitle = document.title
		const reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)

		const cards = new Map() // timer id -> card
		let offset = 0 // server time minus local time, in ms
		let lastServerNow = Date.now()
		let stale = false // true while we wake up and have not yet re-synced
		let settling = false
		let raf = 0
		let dragged = null
		let grabX = 0
		let grabY = 0
		let orderBusy = false
		let audio = null
		let editing = null
		let shareId = null
		let searchTimer = 0
		let searchSeq = 0

		// ------------------------------------------------------------ helpers

		const SVG_NS = 'http://www.w3.org/2000/svg'
		const TAIL_SEGMENTS = 22
		const TAIL_STEP = 2.6 // degrees per segment, about 57 degrees in total

		// A short comet tail behind the pointer: segments that get thinner and
		// fainter. It lives inside the pointer's group, so it rotates together
		// with it and costs nothing extra per frame.
		function buildTail(head) {
			const R = 88
			const point = (deg) => {
				const r = (deg * Math.PI) / 180
				return (100 + R * Math.sin(r)).toFixed(2) + ' ' + (100 - R * Math.cos(r)).toFixed(2)
			}
			const tail = document.createElementNS(SVG_NS, 'g')
			tail.setAttribute('class', 'ttc-tail')
			for (let i = 0; i < TAIL_SEGMENTS; i++) {
				const t = i / TAIL_SEGMENTS
				const path = document.createElementNS(SVG_NS, 'path')
				path.setAttribute('d', 'M ' + point(-(i + 1) * TAIL_STEP) + ' A ' + R + ' ' + R + ' 0 0 1 ' + point(-i * TAIL_STEP + 0.25))
				path.setAttribute('stroke-width', (12 - 8 * t).toFixed(2))
				path.setAttribute('stroke-opacity', (0.6 * Math.pow(1 - t, 1.7)).toFixed(3))
				tail.appendChild(path)
			}
			head.insertBefore(tail, head.firstChild)
		}

		function token() {
			return document.head.dataset.requesttoken || (window.OC && window.OC.requestToken) || ''
		}

		async function request(url, method = 'GET', body = undefined) {
			const res = await fetch(url, {
				method,
				credentials: 'same-origin',
				headers: {
					requesttoken: token(),
					'Content-Type': 'application/json',
					Accept: 'application/json',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			if (!res.ok) {
				const err = new Error('HTTP ' + res.status)
				err.status = res.status
				throw err
			}
			return res.json()
		}

		function timezone() {
			try {
				return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
			} catch (e) {
				return 'UTC'
			}
		}

		function format(sec) {
			const h = Math.floor(sec / 3600)
			const m = Math.floor((sec % 3600) / 60)
			const s = sec % 60
			const mm = String(m).padStart(2, '0')
			const ss = String(s).padStart(2, '0')
			return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss
		}

		const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))
		const serverNow = () => Date.now() + offset

		function remainingMs(c) {
			if (c.data.status === 'running') {
				return Math.max(0, c.data.endsAtMs - serverNow())
			}
			return c.data.remainingMs
		}

		const CAN_MIX = !!(window.CSS && window.CSS.supports
			&& window.CSS.supports('color', 'color-mix(in oklch, red 50%, blue)'))

		// The ring is calm while there is plenty of time, then turns to the
		// warning colour and finally to the danger colour. The colours come from
		// CSS (--ttc-ok, --ttc-warn, --ttc-danger), so they follow the theme.
		function colorFor(f) {
			if (f >= 0.5) {
				return 'var(--ttc-ok)'
			}
			if (!CAN_MIX) {
				return f >= 0.2 ? 'var(--ttc-warn)' : 'var(--ttc-danger)'
			}
			if (f >= 0.25) {
				const p = Math.round(((0.5 - f) / 0.25) * 100)
				return 'color-mix(in oklch, var(--ttc-warn) ' + p + '%, var(--ttc-ok))'
			}
			const p = Math.round(((0.25 - f) / 0.25) * 100)
			return 'color-mix(in oklch, var(--ttc-danger) ' + p + '%, var(--ttc-warn))'
		}

		function showError(message) {
			errorEl.textContent = message
			errorEl.hidden = false
		}

		function handleError(e) {
			console.error(e)
			if (e && e.status) {
				if (e.status === 404 || e.status === 403) {
					refresh().then(() => showError(i18n.errorGeneric))
				} else {
					showError(i18n.errorGeneric)
				}
			} else {
				showError(i18n.errorNetwork)
			}
		}

		function avatarEl(person) {
			const span = document.createElement('span')
			span.className = 'ttc-avatar'
			span.title = person.displayName
			span.textContent = (person.displayName || person.uid || '?').trim().charAt(0).toUpperCase()
			if (avatarTemplate) {
				const img = document.createElement('img')
				img.alt = ''
				img.loading = 'lazy'
				img.draggable = false
				img.src = avatarTemplate.replace('USERID_PLACEHOLDER', encodeURIComponent(person.uid))
				img.addEventListener('error', () => img.remove())
				span.appendChild(img)
			}
			return span
		}

		// -------------------------------------------------------------- sound

		function unlockAudio() {
			const Ctx = window.AudioContext || window.webkitAudioContext
			if (!Ctx) {
				return
			}
			audio = audio || new Ctx()
			if (audio.state === 'suspended') {
				audio.resume()
			}
		}

		// A soft, low chime: three round notes going down, each with a gentle attack
		// and a long fade, passed through a low-pass filter. A quiet octave above the
		// base note keeps it audible on small laptop and phone speakers.
		const CHIME_NOTES = [220, 174.61, 146.83] // A3, F3, D3
		const CHIME_PARTIALS = [[1, 0.8], [2, 0.22]] // [multiple of the note, level]

		function beep() {
			if (!audio) {
				return
			}
			const start = audio.currentTime
			const master = audio.createGain()
			master.gain.value = 0.5
			const filter = audio.createBiquadFilter()
			filter.type = 'lowpass'
			filter.frequency.value = 1100
			master.connect(filter)
			filter.connect(audio.destination)

			CHIME_NOTES.forEach((freq, i) => {
				const t = start + i * 0.55
				CHIME_PARTIALS.forEach(([multiple, level]) => {
					const osc = audio.createOscillator()
					osc.type = 'sine'
					osc.frequency.value = freq * multiple
					const gain = audio.createGain()
					gain.gain.setValueAtTime(0.0001, t)
					gain.gain.exponentialRampToValueAtTime(level * 0.5, t + 0.04)
					gain.gain.exponentialRampToValueAtTime(0.0001, t + 1.3)
					osc.connect(gain)
					gain.connect(master)
					osc.start(t)
					osc.stop(t + 1.35)
				})
			})
		}

		// Beep once per finished timer, and only if it ended just now.
		// An old or stale state must never trigger the alarm.
		function maybeBeep(c) {
			const finishedAt = c.data.finishedAtMs
			if (finishedAt && finishedAt !== c.lastBeepFor && lastServerNow - finishedAt < 10000) {
				c.lastBeepFor = finishedAt
				beep()
			}
		}

		// -------------------------------------------------------------- cards

		function createCard(data) {
			const el = template.content.firstElementChild.cloneNode(true)
			el.dataset.id = String(data.id)
			const c = {
				id: data.id,
				el,
				data,
				shownSecs: -1,
				lastBeepFor: 0,
				avatarSig: null,
				q: {
					name: $('.ttc-name', el),
					desc: $('.ttc-desc', el),
					clock: $('.ttc-clock', el),
					arc: $('.ttc-arc', el),
					head: $('.ttc-head', el),
					by: $('.ttc-by', el),
					avatars: $('.ttc-avatars', el),
					menu: $('.ttc-menu', el),
					menuBtn: $('[data-action="menu"]', el),
				},
			}
			buildTail(c.q.head)
			cards.set(data.id, c)
			grid.appendChild(el)
			return c
		}

		function applyData(c, data) {
			c.data = data
			const { el, q } = c
			el.dataset.status = data.status
			el.dataset.owner = String(data.isOwner)
			el.dataset.resettable = String(data.resettable)
			q.name.textContent = data.name
			q.name.title = data.name
			q.desc.textContent = data.description || ''
			q.desc.title = data.description || ''
			q.by.textContent = data.startedBy || ''
			renderAvatars(c)
			c.shownSecs = -1
			paintText(c)
			paintRing(c)
			if (data.status === 'finished') {
				maybeBeep(c)
			}
			if (shareId === data.id) {
				renderMembers()
			}
		}

		function renderAvatars(c) {
			const others = c.data.members.filter((m) => m.uid !== me)
			const sig = others.map((m) => m.uid).join('|')
			if (sig === c.avatarSig) {
				return
			}
			c.avatarSig = sig
			const box = c.q.avatars
			box.replaceChildren()
			others.slice(0, 4).forEach((m) => box.appendChild(avatarEl(m)))
			if (others.length > 4) {
				const more = document.createElement('span')
				more.className = 'ttc-more'
				more.textContent = '+' + (others.length - 4)
				box.appendChild(more)
			}
		}

		function paintText(c) {
			const secs = Math.ceil(remainingMs(c) / 1000)
			if (secs === c.shownSecs) {
				return
			}
			c.shownSecs = secs
			c.q.clock.textContent = format(secs)
			c.q.clock.classList.toggle('ttc-long', secs >= 3600)
		}

		function paintRing(c) {
			if (stale) {
				return
			}
			const d = c.data
			let f = 1
			if (d.status !== 'finished' && d.duration > 0) {
				f = clamp(remainingMs(c) / (d.duration * 1000), 0, 1)
			}
			const color = d.status === 'finished' ? 'var(--ttc-danger)' : colorFor(f)
			c.q.arc.style.strokeDashoffset = String(-100 * (1 - f))
			c.q.arc.style.stroke = color
			c.q.head.style.setProperty('--ttc-color', color)
			c.q.head.setAttribute('transform', 'rotate(' + ((1 - f) * 360).toFixed(2) + ' 100 100)')
		}

		function removeCard(id) {
			const c = cards.get(id)
			if (c) {
				c.el.remove()
				cards.delete(id)
			}
			emptyEl.hidden = cards.size > 0
			if (shareId === id) {
				dlgShare.close()
			}
		}

		// The rings are redrawn every frame while a timer runs, so they move smoothly.
		function loop() {
			if (raf || document.hidden) {
				return
			}
			const anyRunning = () => [...cards.values()].some((c) => c.data.status === 'running')
			if (!anyRunning()) {
				return
			}
			const step = () => {
				raf = 0
				if (document.hidden || stale) {
					return
				}
				let running = false
				for (const c of cards.values()) {
					if (c.data.status === 'running') {
						paintRing(c)
						running = true
					}
				}
				if (running) {
					raf = requestAnimationFrame(step)
				}
			}
			raf = requestAnimationFrame(step)
		}

		// ------------------------------------------------------- server state

		function renderAll(payload) {
			stale = false
			offset = payload.now - Date.now()
			lastServerNow = payload.now
			errorEl.hidden = true

			const seen = new Set()
			const ordered = []
			for (const t of payload.timers) {
				seen.add(t.id)
				const c = cards.get(t.id) || createCard(t)
				applyData(c, t)
				ordered.push(c.el)
			}
			for (const id of [...cards.keys()]) {
				if (!seen.has(id)) {
					removeCard(id)
				}
			}
			if (!dragged && !orderBusy) {
				syncOrder(ordered)
			}
			emptyEl.hidden = cards.size > 0
			loop()
		}

		function applyOne(payload) {
			stale = false
			offset = payload.now - Date.now()
			lastServerNow = payload.now
			errorEl.hidden = true
			const t = payload.timer
			const c = cards.get(t.id) || createCard(t)
			applyData(c, t)
			emptyEl.hidden = cards.size > 0
			loop()
		}

		async function refresh() {
			try {
				renderAll(await request(api))
			} catch (e) {
				console.error(e)
				showError(i18n.errorNetwork)
			}
		}

		// When the local clock says a time is up, ask the server instead of
		// trusting our own state. The server finishes the timer and sends the
		// notifications.
		async function settle() {
			settling = true
			try {
				await refresh()
			} finally {
				setTimeout(() => { settling = false }, 1000)
			}
		}

		function tick() {
			if (stale) {
				return
			}
			let soonest = null
			let expired = false
			for (const c of cards.values()) {
				paintText(c)
				if (c.data.status === 'running') {
					const r = remainingMs(c)
					if (r <= 0) {
						expired = true
					} else if (soonest === null || r < soonest) {
						soonest = r
					}
				}
			}
			document.title = soonest !== null
				? format(Math.ceil(soonest / 1000)) + ' – ' + baseTitle
				: baseTitle
			if (expired && !settling) {
				settle()
			}
		}

		// Coming back to the page (for example on a phone): drop the old state
		// and fetch a fresh one before showing or reacting to anything.
		async function resync() {
			stale = true
			try {
				await refresh()
			} finally {
				stale = false
			}
		}

		// ------------------------------------------------------ order and drag

		function flip(change) {
			if (reduceMotion) {
				change()
				return
			}
			const before = new Map()
			for (const c of cards.values()) {
				before.set(c.el, c.el.getBoundingClientRect())
			}
			// Stop running animations first, so the new positions are measured
			// without the offset of the previous animation.
			for (const c of cards.values()) {
				if (typeof c.el.getAnimations === 'function') {
					c.el.getAnimations().forEach((anim) => anim.cancel())
				}
			}
			change()
			for (const c of cards.values()) {
				const a = before.get(c.el)
				if (!a) {
					continue
				}
				const b = c.el.getBoundingClientRect()
				const dx = a.left - b.left
				const dy = a.top - b.top
				if (dx || dy) {
					c.el.animate(
						[{ transform: 'translate(' + dx + 'px,' + dy + 'px)' }, { transform: 'none' }],
						{ duration: 180, easing: 'ease' },
					)
				}
			}
		}

		function syncOrder(ordered) {
			const current = [...grid.children]
			if (ordered.length === current.length && ordered.every((el, i) => el === current[i])) {
				return
			}
			flip(() => ordered.forEach((el) => grid.appendChild(el)))
		}

		async function persistOrder() {
			const ids = [...grid.querySelectorAll('.ttc-card')].map((el) => Number(el.dataset.id))
			orderBusy = true
			try {
				await request(api + '/order', 'POST', { ids })
			} catch (e) {
				handleError(e)
			} finally {
				orderBusy = false
			}
		}

		const isRtl = () => window.getComputedStyle(grid).direction === 'rtl'

		// "Left" and "right" are what the user sees on screen. In a right-to-left
		// language the card order runs the other way round.
		function moveSide(c, side) {
			const towardStart = (side === 'left') !== isRtl()
			moveCard(c, towardStart ? -1 : 1)
		}

		function updateMoveButtons(c) {
			const hasPrev = !!c.el.previousElementSibling
			const hasNext = !!c.el.nextElementSibling
			const rtl = isRtl()
			$('[data-action="move-left"]', c.q.menu).disabled = rtl ? !hasNext : !hasPrev
			$('[data-action="move-right"]', c.q.menu).disabled = rtl ? !hasPrev : !hasNext
		}

		function moveCard(c, dir) {
			const el = c.el
			if (dir < 0 && el.previousElementSibling) {
				flip(() => grid.insertBefore(el, el.previousElementSibling))
			} else if (dir > 0 && el.nextElementSibling) {
				flip(() => grid.insertBefore(el.nextElementSibling, el))
			} else {
				return
			}
			persistOrder()
		}

		// Only the grip starts a drag, so buttons and text keep working normally.
		grid.addEventListener('pointerdown', (e) => {
			const grip = e.target.closest('.ttc-grip')
			if (grip) {
				grip.closest('.ttc-card').draggable = true
			}
		})
		document.addEventListener('pointerup', () => {
			if (!dragged) {
				grid.querySelectorAll('.ttc-card[draggable="true"]').forEach((el) => { el.draggable = false })
			}
		})

		grid.addEventListener('dragstart', (e) => {
			const card = e.target
			if (!(card instanceof Element) || !card.classList.contains('ttc-card')) {
				return
			}
			dragged = card
			// Where inside the card the user took hold of it (usually the grip in
			// the corner). The card is placed by its middle, not by the pointer.
			const area = grid.getBoundingClientRect()
			grabX = e.clientX - area.left - card.offsetLeft
			grabY = e.clientY - area.top - card.offsetTop
			closeMenus()
			e.dataTransfer.effectAllowed = 'move'
			e.dataTransfer.setData('text/plain', card.dataset.id)
			requestAnimationFrame(() => card.classList.add('ttc-dragging'))
		})

		// The slot that contains the point, or -1. Only the middle 70 percent of a slot
		// counts, so a shaky hand near the border between two cards cannot make the
		// dragged card flip back and forth: to go back, it must travel well into
		// the other card.
		function indexAtPoint(rects, x, y) {
			const inset = 0.15
			return rects.findIndex((r) => {
				const left = r.left + r.width * inset
				const top = r.top + r.height * inset
				return x >= left && x < r.left + r.width * (1 - inset)
					&& y >= top && y < r.top + r.height * (1 - inset)
			})
		}

		// The dragged card takes over the slot that its own middle is in, as if the
		// card followed the pointer. This works the same wherever the user took hold
		// of the card. All cards have the same size, so slots never move while
		// dragging.
		grid.addEventListener('dragover', (e) => {
			if (!dragged) {
				return
			}
			e.preventDefault()
			e.dataTransfer.dropEffect = 'move'

			const order = [...grid.children]
			const from = order.indexOf(dragged)
			const area = grid.getBoundingClientRect()
			// Layout positions: unlike getBoundingClientRect they are not affected
			// by animations that are still running.
			const rects = order.map((el) => ({
				left: el.offsetLeft,
				top: el.offsetTop,
				width: el.offsetWidth,
				height: el.offsetHeight,
			}))
			const middleX = e.clientX - area.left - grabX + dragged.offsetWidth / 2
			const middleY = e.clientY - area.top - grabY + dragged.offsetHeight / 2
			const to = indexAtPoint(rects, middleX, middleY)
			if (to === -1 || to === from) {
				return
			}
			const ref = to > from ? order[to].nextElementSibling : order[to]
			flip(() => grid.insertBefore(dragged, ref))
		})

		grid.addEventListener('drop', (e) => {
			if (dragged) {
				e.preventDefault()
			}
		})

		grid.addEventListener('dragend', () => {
			if (!dragged) {
				return
			}
			dragged.classList.remove('ttc-dragging')
			dragged.draggable = false
			dragged = null
			persistOrder()
		})

		// -------------------------------------------------------------- menus

		function closeMenus() {
			for (const c of cards.values()) {
				if (!c.q.menu.hidden) {
					c.q.menu.hidden = true
					c.q.menuBtn.setAttribute('aria-expanded', 'false')
					c.el.classList.remove('ttc-menu-open')
				}
			}
		}

		function toggleMenu(c) {
			const open = c.q.menu.hidden
			closeMenus()
			if (open) {
				updateMoveButtons(c)
				c.q.menu.hidden = false
				c.q.menuBtn.setAttribute('aria-expanded', 'true')
				c.el.classList.add('ttc-menu-open')
			}
		}

		// ------------------------------------------------------------- editor

		function openEditor(c) {
			editing = c
			$('#ttc-edit-title').textContent = c ? i18n.titleEdit : i18n.titleCreate
			$('#ttc-f-name').value = c ? c.data.name : ''
			$('#ttc-f-desc').value = c ? (c.data.description || '') : ''
			updateCount()
			$('#ttc-f-minutes').value = c ? Math.round(c.data.duration / 60) : 45
			$('#ttc-f-resettable').checked = c ? c.data.resettable : true
			$('#ttc-edit-save').textContent = c ? i18n.save : i18n.create
			$('#ttc-edit-error').hidden = true
			dlgEdit.showModal()
			$('#ttc-f-name').focus()
		}

		function updateCount() {
			$('#ttc-desc-count').textContent = String($('#ttc-f-desc').value.length)
		}
		$('#ttc-f-desc').addEventListener('input', updateCount)

		$('#ttc-form-edit').addEventListener('submit', async (e) => {
			e.preventDefault()
			const errorBox = $('#ttc-edit-error')
			const name = $('#ttc-f-name').value.trim()
			if (!name) {
				errorBox.textContent = i18n.nameRequired
				errorBox.hidden = false
				return
			}
			const body = {
				name,
				description: $('#ttc-f-desc').value.trim(),
				minutes: parseInt($('#ttc-f-minutes').value, 10) || 45,
				resettable: $('#ttc-f-resettable').checked,
				timezone: timezone(),
			}
			const save = $('#ttc-edit-save')
			save.disabled = true
			try {
				const payload = editing
					? await request(api + '/' + editing.id, 'PUT', body)
					: await request(api, 'POST', body)
				applyOne(payload)
				dlgEdit.close()
			} catch (err) {
				console.error(err)
				errorBox.textContent = err && err.status ? i18n.errorGeneric : i18n.errorNetwork
				errorBox.hidden = false
			} finally {
				save.disabled = false
			}
		})

		// -------------------------------------------------------------- share

		function personRow(person) {
			const li = document.createElement('li')
			li.className = 'ttc-person'
			li.appendChild(avatarEl(person))
			const name = document.createElement('span')
			name.className = 'ttc-person-name'
			name.textContent = person.displayName
			li.appendChild(name)
			return li
		}

		function openShare(c) {
			shareId = c.id
			$('#ttc-search').value = ''
			$('#ttc-results').replaceChildren()
			$('#ttc-noresults').hidden = true
			renderMembers()
			dlgShare.showModal()
		}

		function renderMembers() {
			const c = cards.get(shareId)
			const list = $('#ttc-members')
			list.replaceChildren()
			if (!c) {
				return
			}
			$('#ttc-share-name').textContent = c.data.name
			for (const m of c.data.members) {
				const li = personRow(m)
				if (m.isOwner) {
					const badge = document.createElement('span')
					badge.className = 'ttc-badge'
					badge.textContent = i18n.owner
					li.appendChild(badge)
				} else if (c.data.isOwner) {
					const btn = document.createElement('button')
					btn.type = 'button'
					btn.textContent = i18n.remove
					btn.dataset.action = 'remove-member'
					btn.dataset.uid = m.uid
					li.appendChild(btn)
				}
				list.appendChild(li)
			}
		}

		function renderResults(users, query) {
			const list = $('#ttc-results')
			list.replaceChildren()
			$('#ttc-noresults').hidden = users.length > 0 || query === ''
			for (const u of users) {
				const li = personRow(u)
				const btn = document.createElement('button')
				btn.type = 'button'
				btn.className = 'primary'
				btn.textContent = i18n.invite
				btn.dataset.action = 'invite'
				btn.dataset.uid = u.uid
				li.appendChild(btn)
				list.appendChild(li)
			}
		}

		async function doSearch() {
			const query = $('#ttc-search').value.trim()
			if (!query || shareId === null) {
				renderResults([], '')
				return
			}
			const seq = ++searchSeq
			try {
				const res = await request(usersUrl + '?search=' + encodeURIComponent(query) + '&timerId=' + shareId)
				if (seq === searchSeq) {
					renderResults(res.users || [], query)
				}
			} catch (e) {
				handleError(e)
			}
		}

		$('#ttc-search').addEventListener('input', () => {
			clearTimeout(searchTimer)
			searchTimer = setTimeout(doSearch, 250)
		})
		dlgShare.addEventListener('close', () => {
			shareId = null
			clearTimeout(searchTimer)
		})
		for (const dlg of [dlgEdit, dlgShare]) {
			dlg.addEventListener('click', (e) => {
				if (e.target === dlg) {
					dlg.close()
				}
			})
		}

		// ------------------------------------------------------------ actions

		async function act(c, action, btn) {
			btn.disabled = true
			try {
				applyOne(await request(api + '/' + c.id + '/' + action, 'POST'))
			} catch (e) {
				handleError(e)
			} finally {
				btn.disabled = false
			}
		}

		root.addEventListener('click', async (e) => {
			const btn = e.target.closest('[data-action]')
			if (!btn || btn.dataset.action !== 'menu') {
				closeMenus()
			}
			if (!btn) {
				return
			}
			unlockAudio()

			const action = btn.dataset.action
			const cardEl = btn.closest('.ttc-card')
			const c = cardEl ? cards.get(Number(cardEl.dataset.id)) : null

			try {
				switch (action) {
				case 'new':
					openEditor(null)
					break
				case 'menu':
					if (c) {
						toggleMenu(c)
					}
					break
				case 'start':
				case 'pause':
				case 'reset':
					if (c) {
						await act(c, action, btn)
					}
					break
				case 'edit':
					if (c) {
						openEditor(c)
					}
					break
				case 'share':
					if (c) {
						openShare(c)
					}
					break
				case 'move-left':
					if (c) {
						moveSide(c, 'left')
					}
					break
				case 'move-right':
					if (c) {
						moveSide(c, 'right')
					}
					break
				case 'leave':
					if (c && window.confirm(i18n.confirmLeave)) {
						await request(api + '/' + c.id + '/leave', 'POST')
						removeCard(c.id)
					}
					break
				case 'delete':
					if (c && window.confirm(i18n.confirmDelete)) {
						await request(api + '/' + c.id, 'DELETE')
						removeCard(c.id)
					}
					break
				case 'cancel-edit':
					dlgEdit.close()
					break
				case 'close-share':
					dlgShare.close()
					break
				case 'invite':
					if (shareId !== null) {
						applyOne(await request(api + '/' + shareId + '/members', 'POST', { uid: btn.dataset.uid }))
						btn.closest('li').remove()
					}
					break
				case 'remove-member':
					if (shareId !== null) {
						applyOne(await request(
							api + '/' + shareId + '/members/' + encodeURIComponent(btn.dataset.uid),
							'DELETE',
						))
					}
					break
				default:
					break
				}
			} catch (err) {
				handleError(err)
			}
		})

		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape') {
				closeMenus()
			}
		})
		document.addEventListener('visibilitychange', () => {
			if (!document.hidden) {
				resync()
			}
		})
		window.addEventListener('pageshow', (e) => {
			if (e.persisted) {
				resync()
			}
		})

		setInterval(tick, 250)
		setInterval(() => {
			if (!document.hidden && !dragged && !orderBusy) {
				refresh()
			}
		}, 5000)
		refresh()
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
