(function () {
	'use strict'

	const RING = '<div class="ttc-ring"><svg class="ttc-svg" viewBox="0 0 200 200" aria-hidden="true" focusable="false">'
		+ '<circle class="ttc-track" cx="100" cy="100" r="88"/>'
		+ '<circle class="ttc-arc" cx="100" cy="100" r="88" pathLength="100" transform="rotate(-90 100 100)"/>'
		+ '</svg></div>'

	const CAN_MIX = !!(window.CSS && window.CSS.supports
		&& window.CSS.supports('color', 'color-mix(in oklch, red 50%, blue)'))

	const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v))

	function format(sec) {
		const h = Math.floor(sec / 3600)
		const m = Math.floor((sec % 3600) / 60)
		const s = sec % 60
		const mm = String(m).padStart(2, '0')
		const ss = String(s).padStart(2, '0')
		return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss
	}

	// Same colour ramp as on the main page, taken from the CSS variables.
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

	function readConfig() {
		const meta = document.querySelector('meta[name="timetrackercards-widget"]')
		if (!meta) {
			return null
		}
		try {
			return JSON.parse(meta.getAttribute('content'))
		} catch (e) {
			return null
		}
	}

	function mount(el) {
		const cfg = readConfig()
		if (!cfg || !cfg.api) {
			return
		}
		const labels = cfg.labels || {}

		el.replaceChildren()
		const root = document.createElement('div')
		root.className = 'ttc-widget'
		const list = document.createElement('ul')
		list.className = 'ttc-wlist'
		const empty = document.createElement('p')
		empty.className = 'ttc-wempty'
		empty.textContent = labels.empty || ''
		empty.hidden = true
		const error = document.createElement('p')
		error.className = 'ttc-werror'
		error.textContent = labels.error || ''
		error.hidden = true
		const more = document.createElement('a')
		more.className = 'ttc-wmore'
		more.href = cfg.url || '#'
		more.textContent = labels.showAll || ''
		root.append(list, empty, error, more)
		el.appendChild(root)

		const rows = new Map() // timer id -> row
		let offset = 0 // server time minus local time, in ms
		let stale = false
		let settling = false
		let raf = 0

		const serverNow = () => Date.now() + offset

		function token() {
			return document.head.dataset.requesttoken || (window.OC && window.OC.requestToken) || ''
		}

		async function request(url, method = 'GET') {
			const res = await fetch(url, {
				method,
				credentials: 'same-origin',
				headers: { requesttoken: token(), Accept: 'application/json' },
			})
			if (!res.ok) {
				throw new Error('HTTP ' + res.status)
			}
			return res.json()
		}

		function remainingMs(row) {
			if (row.data.status === 'running') {
				return Math.max(0, row.data.endsAtMs - serverNow())
			}
			return row.data.remainingMs
		}

		function paintRing(row) {
			if (stale) {
				return
			}
			const d = row.data
			let f = 1
			if (d.status !== 'finished' && d.duration > 0) {
				f = clamp(remainingMs(row) / (d.duration * 1000), 0, 1)
			}
			row.arc.style.strokeDashoffset = String(-100 * (1 - f))
			row.arc.style.stroke = d.status === 'finished' ? 'var(--ttc-danger)' : colorFor(f)
		}

		function paintText(row) {
			if (stale) {
				return
			}
			const secs = Math.ceil(remainingMs(row) / 1000)
			if (secs !== row.shownSecs) {
				row.shownSecs = secs
				row.time.textContent = format(secs)
			}
		}

		function paintButton(row) {
			const d = row.data
			let action = 'start'
			let label = labels.start
			if (d.status === 'running') {
				action = 'pause'
				label = labels.pause
			} else if (d.status === 'paused') {
				label = labels.resume
			}
			row.btn.dataset.action = action
			row.btn.textContent = label || ''
			// A daily allowance that is used up cannot be started again.
			row.btn.hidden = d.status === 'finished' && !d.resettable
			row.btn.classList.toggle('primary', action === 'start')
		}

		function createRow(data) {
			const li = document.createElement('li')
			li.className = 'ttc-wrow'
			li.innerHTML = RING
				+ '<div class="ttc-wtext"><span class="ttc-wname"></span><span class="ttc-wtime"></span></div>'
				+ '<button type="button" class="ttc-wbtn"></button>'
			const row = {
				id: data.id,
				el: li,
				data,
				shownSecs: -1,
				name: li.querySelector('.ttc-wname'),
				time: li.querySelector('.ttc-wtime'),
				arc: li.querySelector('.ttc-arc'),
				btn: li.querySelector('.ttc-wbtn'),
			}
			li.dataset.id = String(data.id)
			rows.set(data.id, row)
			list.appendChild(li)
			return row
		}

		function applyRow(row, data) {
			row.data = data
			row.el.dataset.status = data.status
			row.name.textContent = data.name
			row.name.title = data.name
			row.shownSecs = -1
			paintText(row)
			paintRing(row)
			paintButton(row)
		}

		function loop() {
			if (raf || document.hidden) {
				return
			}
			if (![...rows.values()].some((r) => r.data.status === 'running')) {
				return
			}
			const step = () => {
				raf = 0
				if (document.hidden || stale || !root.isConnected) {
					return
				}
				let running = false
				for (const row of rows.values()) {
					if (row.data.status === 'running') {
						paintRing(row)
						running = true
					}
				}
				if (running) {
					raf = requestAnimationFrame(step)
				}
			}
			raf = requestAnimationFrame(step)
		}

		function applyAll(payload) {
			stale = false
			offset = payload.now - Date.now()
			error.hidden = true
			const seen = new Set()
			const ordered = []
			for (const t of payload.timers) {
				seen.add(t.id)
				const row = rows.get(t.id) || createRow(t)
				applyRow(row, t)
				ordered.push(row.el)
			}
			for (const id of [...rows.keys()]) {
				if (!seen.has(id)) {
					rows.get(id).el.remove()
					rows.delete(id)
				}
			}
			const current = [...list.children]
			if (ordered.length !== current.length || ordered.some((e, i) => e !== current[i])) {
				ordered.forEach((e) => list.appendChild(e))
			}
			empty.hidden = rows.size > 0
			loop()
		}

		function applyOne(payload) {
			stale = false
			offset = payload.now - Date.now()
			error.hidden = true
			const t = payload.timer
			applyRow(rows.get(t.id) || createRow(t), t)
			empty.hidden = rows.size > 0
			loop()
		}

		async function refresh() {
			try {
				applyAll(await request(cfg.api))
			} catch (e) {
				console.error(e)
				error.hidden = false
			}
		}

		async function settle() {
			settling = true
			try {
				await refresh()
			} finally {
				setTimeout(() => { settling = false }, 1000)
			}
		}

		async function resync() {
			stale = true
			try {
				await refresh()
			} finally {
				stale = false
			}
		}

		list.addEventListener('click', async (e) => {
			const btn = e.target.closest('.ttc-wbtn')
			if (!btn) {
				return
			}
			const id = Number(btn.closest('.ttc-wrow').dataset.id)
			btn.disabled = true
			try {
				applyOne(await request(cfg.api + '/' + id + '/' + btn.dataset.action, 'POST'))
			} catch (err) {
				console.error(err)
				error.hidden = false
				refresh()
			} finally {
				btn.disabled = false
			}
		})

		const tickTimer = setInterval(() => {
			if (!root.isConnected) {
				clearInterval(tickTimer)
				clearInterval(pollTimer)
				return
			}
			if (stale) {
				return
			}
			let expired = false
			for (const row of rows.values()) {
				paintText(row)
				if (row.data.status === 'running' && remainingMs(row) <= 0) {
					expired = true
				}
			}
			if (expired && !settling) {
				settle()
			}
		}, 250)
		const pollTimer = setInterval(() => {
			if (!document.hidden && root.isConnected) {
				refresh()
			}
		}, 5000)

		document.addEventListener('visibilitychange', () => {
			if (!document.hidden && root.isConnected) {
				resync()
			}
		})

		refresh()
	}

	function register() {
		if (window.OCA && window.OCA.Dashboard && typeof window.OCA.Dashboard.register === 'function') {
			window.OCA.Dashboard.register('timetrackercards', (el) => mount(el))
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', register)
	} else {
		register()
	}
})()
