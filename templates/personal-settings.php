<?php
script('hyperviewer', 'settings');
style('hyperviewer', 'settings');
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

	<!-- Active Jobs -->
	<h3><?php p($l->t('Active Jobs')); ?></h3>
	<div id="active-jobs-container">
		<p class="emptycontent-desc"><?php p($l->t('No active jobs running')); ?></p>
	</div>

	<!-- Auto-Generation Directories -->
	<h3><?php p($l->t('Auto-Generation Directories')); ?></h3>
	<div id="autogen-container">
		<p class="emptycontent-desc"><?php p($l->t('No auto-generation directories configured')); ?></p>
	</div>
</div>
