import '../webpack-public-path.js'
import Vue from 'vue'
import App from './App.vue'

document.addEventListener('DOMContentLoaded', () => {
	const mount = document.getElementById('hyperviewer-personal')
	if (mount) {
		// eslint-disable-next-line no-new
		new Vue({
			render: h => h(App),
		}).$mount(mount)
	}
})
