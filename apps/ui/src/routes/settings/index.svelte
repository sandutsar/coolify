<script context="module" lang="ts">
	import type { Load } from '@sveltejs/kit';
	export const load: Load = async () => {
		try {
			const response = await get(`/settings`);
			return {
				props: {
					...response
				}
			};
		} catch (error: any) {
			return {
				status: 500,
				error: new Error(error)
			};
		}
	};
</script>

<script lang="ts">
	export let settings: any;

	import Setting from '$lib/components/Setting.svelte';
	import Explainer from '$lib/components/Explainer.svelte';
	import { del, get, post } from '$lib/api';
	import CopyPasswordField from '$lib/components/CopyPasswordField.svelte';
	import { browser } from '$app/env';
	import { toast } from '@zerodevx/svelte-toast';
	import { t } from '$lib/translations';
	import { appSession, features, isTraefikUsed } from '$lib/store';
	import { errorNotification, getDomain } from '$lib/common';

	let isRegistrationEnabled = settings.isRegistrationEnabled;
	let dualCerts = settings.dualCerts;
	let isAutoUpdateEnabled = settings.isAutoUpdateEnabled;
	let isDNSCheckEnabled = settings.isDNSCheckEnabled;
	$isTraefikUsed = settings.isTraefikUsed;

	let minPort = settings.minPort;
	let maxPort = settings.maxPort;

	let forceSave = false;
	let fqdn = settings.fqdn;
	let nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');
	let isNonWWWDomainOK = false;
	let isWWWDomainOK = false;
	let isFqdnSet = !!settings.fqdn;
	let loading = {
		save: false,
		remove: false,
		proxyMigration: false
	};

	async function removeFqdn() {
		if (fqdn) {
			loading.remove = true;
			try {
				const { redirect } = await del(`/settings`, { fqdn });
				return redirect ? window.location.replace(redirect) : window.location.reload();
			} catch (error ) {
				return errorNotification(error);
			} finally {
				loading.remove = false;
			}
		}
	}
	async function changeSettings(name: any) {
		try {
			resetView();
			if (name === 'isRegistrationEnabled') {
				isRegistrationEnabled = !isRegistrationEnabled;
			}
			if (name === 'dualCerts') {
				dualCerts = !dualCerts;
			}
			if (name === 'isAutoUpdateEnabled') {
				isAutoUpdateEnabled = !isAutoUpdateEnabled;
			}
			if (name === 'isDNSCheckEnabled') {
				isDNSCheckEnabled = !isDNSCheckEnabled;
			}

			await post(`/settings`, {
				isRegistrationEnabled,
				dualCerts,
				isAutoUpdateEnabled,
				isDNSCheckEnabled
			});
			return toast.push(t.get('application.settings_saved'));
		} catch (error) {
			return errorNotification(error);
		}
	}
	async function handleSubmit() {
		try {
			loading.save = true;
			nonWWWDomain = fqdn && getDomain(fqdn).replace(/^www\./, '');

			if (fqdn !== settings.fqdn) {
				await post(`/settings/check`, { fqdn, forceSave, dualCerts, isDNSCheckEnabled });
				await post(`/settings`, { fqdn });
				return window.location.reload();
			}
			if (minPort !== settings.minPort || maxPort !== settings.maxPort) {
				await post(`/settings`, { minPort, maxPort });
				settings.minPort = minPort;
				settings.maxPort = maxPort;
			}
			forceSave = false;
		} catch (error ) {
			if (error.message?.startsWith($t('application.dns_not_set_partial_error'))) {
				forceSave = true;
				if (dualCerts) {
					isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
				} else {
					const isWWW = getDomain(settings.fqdn).includes('www.');
					if (isWWW) {
						isWWWDomainOK = await isDNSValid(getDomain(`www.${nonWWWDomain}`), true);
					} else {
						isNonWWWDomainOK = await isDNSValid(getDomain(nonWWWDomain), false);
					}
				}
			}
			console.log(error)
			return errorNotification(error);
		} finally {
			loading.save = false;
		}
	}
	async function isDNSValid(domain: any, isWWW: any) {
		try {
			await get(`/settings/check?domain=${domain}`);
			toast.push('DNS configuration is valid.');
			isWWW ? (isWWWDomainOK = true) : (isNonWWWDomainOK = true);
			return true;
		} catch (error) {
			errorNotification(error);
			isWWW ? (isWWWDomainOK = false) : (isNonWWWDomainOK = false);
			return false;
		}
	}
	function resetView() {
		forceSave = false;
	}
	async function migrateProxy(to: any) {
		if (loading.proxyMigration) return;
		try {
			loading.proxyMigration = true;
			await post(`/update`, { type: to });
			const data = await get(`/settings`);
			$isTraefikUsed = data.settings.isTraefikUsed;
			return toast.push('Proxy migration started, it takes a few seconds.');
		} catch (error) {
			return errorNotification(error);
		} finally {
			loading.proxyMigration = false;
		}
	}
</script>

<div class="flex space-x-1 p-6 font-bold">
	<div class="mr-4 text-2xl tracking-tight">{$t('index.settings')}</div>
</div>
{#if $appSession.teamId === '0'}
	<div class="mx-auto max-w-4xl px-6">
		<form on:submit|preventDefault={handleSubmit} class="grid grid-flow-row gap-2 py-4">
			<div class="flex space-x-1 pb-6">
				<div class="title font-bold">{$t('index.global_settings')}</div>
				<button
					type="submit"
					class:bg-green-600={!loading.save}
					class:bg-orange-600={forceSave}
					class:hover:bg-green-500={!loading.save}
					class:hover:bg-orange-400={forceSave}
					disabled={loading.save}
					>{loading.save
						? $t('forms.saving')
						: forceSave
						? $t('forms.confirm_continue')
						: $t('forms.save')}</button
				>

				{#if isFqdnSet}
					<button
						on:click|preventDefault={removeFqdn}
						disabled={loading.remove}
						class:bg-red-600={!loading.remove}
						class:hover:bg-red-500={!loading.remove}
						>{loading.remove ? $t('forms.removing') : $t('forms.remove_domain')}</button
					>
				{/if}
			</div>
			<div class="grid grid-flow-row gap-2 px-10">
				<!-- <Language /> -->
				<div class="grid grid-cols-2 items-start">
					<div class="flex-col">
						<div class="pt-2 text-base font-bold text-stone-100">
							{$t('application.url_fqdn')}
						</div>
						<Explainer text={$t('setting.ssl_explainer')} />
					</div>
					<div class="justify-start text-left">
						<input
							bind:value={fqdn}
							readonly={!$appSession.isAdmin || isFqdnSet}
							disabled={!$appSession.isAdmin || isFqdnSet}
							on:input={resetView}
							name="fqdn"
							id="fqdn"
							pattern="^https?://([a-z0-9]+(-[a-z0-9]+)*\.)+[a-z]{'{'}2,{'}'}$"
							placeholder="{$t('forms.eg')}: https://coolify.io"
						/>

						{#if forceSave}
							<div class="flex-col space-y-2 pt-4 text-center">
								{#if isNonWWWDomainOK}
									<button
										class="bg-green-600 hover:bg-green-500"
										on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
										>DNS settings for {nonWWWDomain} is OK, click to recheck.</button
									>
								{:else}
									<button
										class="bg-red-600 hover:bg-red-500"
										on:click|preventDefault={() => isDNSValid(getDomain(nonWWWDomain), false)}
										>DNS settings for {nonWWWDomain} is invalid, click to recheck.</button
									>
								{/if}
								{#if dualCerts}
									{#if isWWWDomainOK}
										<button
											class="bg-green-600 hover:bg-green-500"
											on:click|preventDefault={() =>
												isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
											>DNS settings for www.{nonWWWDomain} is OK, click to recheck.</button
										>
									{:else}
										<button
											class="bg-red-600 hover:bg-red-500"
											on:click|preventDefault={() =>
												isDNSValid(getDomain(`www.${nonWWWDomain}`), true)}
											>DNS settings for www.{nonWWWDomain} is invalid, click to recheck.</button
										>
									{/if}
								{/if}
							</div>
						{/if}
					</div>
				</div>
				<div class="grid grid-cols-2 items-start py-6">
					<div class="flex-col">
						<div class="pt-2 text-base font-bold text-stone-100">
							{$t('forms.public_port_range')}
						</div>
						<Explainer text={$t('forms.public_port_range_explainer')} />
					</div>
					<div class="mx-auto flex-row items-center justify-center space-y-2">
						<input
							class="h-8 w-20 px-2"
							type="number"
							bind:value={minPort}
							min="1024"
							max={maxPort}
						/>
						-
						<input
							class="h-8 w-20 px-2"
							type="number"
							bind:value={maxPort}
							min={minPort}
							max="65543"
						/>
					</div>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						bind:setting={isDNSCheckEnabled}
						title={$t('setting.is_dns_check_enabled')}
						description={$t('setting.is_dns_check_enabled_explainer')}
						on:click={() => changeSettings('isDNSCheckEnabled')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						dataTooltip={$t('setting.must_remove_domain_before_changing')}
						disabled={isFqdnSet}
						bind:setting={dualCerts}
						title={$t('application.ssl_www_and_non_www')}
						description={$t('setting.generate_www_non_www_ssl')}
						on:click={() => !isFqdnSet && changeSettings('dualCerts')}
					/>
				</div>
				<div class="grid grid-cols-2 items-center">
					<Setting
						bind:setting={isRegistrationEnabled}
						title={$t('setting.registration_allowed')}
						description={$t('setting.registration_allowed_explainer')}
						on:click={() => changeSettings('isRegistrationEnabled')}
					/>
				</div>
				{#if browser && $features.beta}
					<div class="grid grid-cols-2 items-center">
						<Setting
							bind:setting={isAutoUpdateEnabled}
							title={$t('setting.auto_update_enabled')}
							description={$t('setting.auto_update_enabled_explainer')}
							on:click={() => changeSettings('isAutoUpdateEnabled')}
						/>
					</div>
				{/if}
			</div>
		</form>
		{#if !settings.isTraefikUsed}
		<div class="flex space-x-1 pt-6 font-bold">
			<div class="title">{$t('setting.coolify_proxy_settings')}</div>
		</div>
		<Explainer
			text={$t('setting.credential_stat_explainer', {
				link: fqdn
					? `http://${settings.proxyUser}:${settings.proxyPassword}@` + getDomain(fqdn) + ':8404'
					: browser &&
					  `http://${settings.proxyUser}:${settings.proxyPassword}@` +
							window.location.hostname +
							':8404'
			})}
		/>
		<div class="space-y-2 px-10 py-5">
			<div class="grid grid-cols-2 items-center">
				<label for="proxyUser">{$t('forms.user')}</label>
				<CopyPasswordField
					readonly
					disabled
					id="proxyUser"
					name="proxyUser"
					value={settings.proxyUser}
				/>
			</div>
			<div class="grid grid-cols-2 items-center">
				<label for="proxyPassword">{$t('forms.password')}</label>
				<CopyPasswordField
					readonly
					disabled
					id="proxyPassword"
					name="proxyPassword"
					isPasswordField
					value={settings.proxyPassword}
				/>
			</div>
		</div>
		{/if}
	</div>
{:else}
	<div class="mx-auto max-w-4xl px-6">
		<!-- <Language /> -->
	</div>
{/if}
