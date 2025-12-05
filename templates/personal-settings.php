<?php
style('hyperviewer', 'settings');
style('hyperviewer', 'job-management');
script('hyperviewer', 'settings');
script('hyperviewer', 'job-management');
?>

<div class="section" id="hyperviewer_settings">
	<h2 class="inlineblock"><?php p($l->t('Hyper Viewer')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Monitor video processing jobs and auto-generation directories.')); ?></p>
	
	<!-- Job Statistics -->
	<h3><?php p($l->t('Job Statistics')); ?></h3>
	<table class="grid">
		<thead>
			<tr>
				<th><?php p($l->t('Active Jobs')); ?></th>
				<th><?php p($l->t('Auto-Gen Directories')); ?></th>
				<th><?php p($l->t('Completed')); ?></th>
				<th><?php p($l->t('Pending')); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td id="stat-active" class="stat-value">0</td>
				<td id="stat-autogen" class="stat-value">0</td>
				<td id="stat-completed" class="stat-value">0</td>
				<td id="stat-pending" class="stat-value">0</td>
			</tr>
		</tbody>
	</table>

	<!-- Active Jobs Section -->
	<h3><?php p($l->t('Job Queue')); ?></h3>
	<p class="settings-hint"><?php p($l->t('Monitor transcoding jobs in real-time')); ?></p>
	
	<button id="refresh-jobs-btn" class="button">
		<?php p($l->t('Refresh Data')); ?>
	</button>

	<div class="jobs-grid">
		<!-- Current Jobs Column -->
		<div class="jobs-column current">
			<div class="jobs-column-header">
				<span><?php p($l->t('Current')); ?></span>
				<span class="jobs-column-count" id="jobs-current-count">0</span>
			</div>
			<div class="jobs-column-body" id="jobs-current">
				<div class="jobs-loading">Loading...</div>
			</div>
		</div>

		<!-- Done Jobs Column -->
		<div class="jobs-column done">
			<div class="jobs-column-header">
				<span><?php p($l->t('Done')); ?></span>
				<span class="jobs-column-count" id="jobs-done-count">0</span>
			</div>
			<div class="jobs-column-body" id="jobs-done">
				<div class="jobs-loading">Loading...</div>
			</div>
		</div>

		<!-- Failed Jobs Column -->
		<div class="jobs-column failed">
			<div class="jobs-column-header">
				<span><?php p($l->t('Failed')); ?></span>
				<span class="jobs-column-count" id="jobs-failed-count">0</span>
			</div>
			<div class="jobs-column-body" id="jobs-failed">
				<div class="jobs-loading">Loading...</div>
			</div>
		</div>
	</div>

	<!-- Auto-Generation Directories -->
	<h3><?php p($l->t('Auto-Generation Directories')); ?></h3>
	<div id="autogen-container">
		<p class="emptycontent-desc"><?php p($l->t('No auto-generation directories configured')); ?></p>
	</div>

	<!-- Completed Jobs -->
	<h3><?php p($l->t('Completed Jobs')); ?></h3>
	<details class="completed-jobs-section">
		<summary>
			<?php p($l->t('Show Completed Jobs')); ?> 
			<span id="completed-count-badge" class="count-badge">0</span>
		</summary>
		<div class="completed-jobs-content">
			<div class="search-box">
				<input type="text" id="completed-jobs-search" placeholder="<?php p($l->t('Search completed jobs...')); ?>" />
			</div>
			<ul id="completed-jobs-list" class="job-list">
				<!-- Populated by JS -->
			</ul>
			<p id="no-completed-jobs" class="emptycontent-desc" style="display:none;"><?php p($l->t('No completed jobs found')); ?></p>
		</div>
	</details>

	<div class="section-footer">
		<button id="refresh-stats" class="primary icon-refresh" title="<?php p($l->t('Last updated: Never')); ?>">
			<?php p($l->t('Refresh Data')); ?>
		</button>
		<span id="last-updated-time" class="timestamp"></span>
	</div>
</div>
