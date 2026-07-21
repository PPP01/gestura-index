<script lang="ts">
	import type { PageProps } from './$types';
	import {
		getEntry,
		downloadVersion,
		downloadVersionUrl,
		pingInstall,
		reportEntry,
		ApiError,
		type EntryDetail,
		type ReportReason
	} from '$lib/api';
	import { triggerJsonDownload, buildDownloadFilename } from '$lib/download';
	import { localizeHref, getLocale } from '$lib/paraglide/runtime';
	import { m } from '$lib/paraglide/messages.js';
	import { categoryLabel } from '$lib/categories';
	import Badge from '$lib/components/Badge.svelte';
	import Spinner from '$lib/components/Spinner.svelte';
	import ErrorState from '$lib/components/ErrorState.svelte';
	import { TriangleAlert } from '@lucide/svelte';

	// `PageProps` liefert `params.formatId` korrekt als `string` (nicht optional) –
	// `page.params` aus `$app/state` wäre hier wegen der generischen Root-Typisierung
	// als `string | undefined` typisiert.
	let { params }: PageProps = $props();
	const formatId = $derived(params.formatId);
	let entry = $state<EntryDetail | null>(null);
	let loading = $state(true);
	let notFound = $state(false);
	let error = $state<string | null>(null);

	let copied = $state(false);
	let downloadError = $state<string | null>(null);
	let reportReason = $state<ReportReason>('spam');
	let reportComment = $state('');
	let reportSent = $state(false);
	let reportError = $state<string | null>(null);
	let reportSubmitting = $state(false);

	async function load() {
		loading = true;
		error = null;
		notFound = false;
		try {
			entry = await getEntry(formatId);
		} catch (e) {
			if (e instanceof ApiError && e.status === 404) {
				notFound = true;
			} else {
				error = e instanceof Error ? e.message : String(e);
			}
		} finally {
			loading = false;
		}
	}
	$effect(() => {
		load();
	});

	const currentVersion = $derived(entry?.currentVersion ?? null);
	const currentHasTransform = $derived(
		entry?.versions.find((v) => v.semver === currentVersion)?.hasTransformCode ?? false
	);

	async function download() {
		if (!entry || !currentVersion) return;
		downloadError = null;
		try {
			const data = await downloadVersion(entry.formatId, currentVersion);
			triggerJsonDownload(data, buildDownloadFilename(entry.formatId, currentVersion));
		} catch (e) {
			downloadError = e instanceof Error ? e.message : String(e);
			return;
		}
		try {
			await pingInstall(entry.formatId);
		} catch {
			/* Install-Ping ist Best-Effort – Fehler nicht anzeigen. */
		}
	}
	async function copyUrl() {
		if (!entry || !currentVersion) return;
		try {
			await navigator.clipboard.writeText(downloadVersionUrl(entry.formatId, currentVersion));
			copied = true;
			setTimeout(() => (copied = false), 1500);
		} catch {
			/* Zwischenablage nicht verfügbar – still ignorieren. */
		}
	}
	async function submitReport(e: Event) {
		e.preventDefault();
		if (!entry) return;
		reportError = null;
		reportSubmitting = true;
		try {
			await reportEntry(entry.formatId, {
				reason: reportReason,
				comment: reportComment.trim() || undefined
			});
			reportSent = true;
		} catch (err) {
			reportError = err instanceof Error ? err.message : String(err);
		} finally {
			reportSubmitting = false;
		}
	}
</script>

<svelte:head>
	<title>{entry?.name ?? formatId} · Gestura Index</title>
</svelte:head>

{#if loading}
	<Spinner />
{:else if notFound}
	<div class="card" style="text-align:center; padding:32px;">
		<h1>{m.detail_not_found()}</h1>
		<a class="btn" href={localizeHref('/browse')}>{m.detail_back()}</a>
	</div>
{:else if error}
	<ErrorState message={error} onRetry={load} />
{:else if entry}
	<article>
		<header class="detail-head">
			<h1>{entry.name}</h1>
			<Badge text={entry.type === 'menu' ? m.type_menu() : m.type_engine()} />
			{#if entry.deprecated}<Badge text={m.badge_deprecated()} variant="warning" />{/if}
		</header>

		{#if entry.deprecated}
			<p class="notice">{m.detail_deprecated()}
				{#if entry.successorFormatId}
					· {m.detail_successor()}: <a href={localizeHref(`/entry/${entry.successorFormatId}`)}>{entry.successorFormatId}</a>
				{/if}
			</p>
		{/if}

		<div class="detail-cats">
			{#each entry.categories as cat}<Badge text={categoryLabel(cat)} />{/each}
			{#each entry.tags as tag}<Badge text={`#${tag}`} />{/each}
		</div>

		{#if entry.screenshotUrl}
			<img class="screenshot" src={entry.screenshotUrl} alt={entry.name} loading="lazy" />
		{/if}

		{#if entry.description}<p>{entry.description}</p>{/if}

		{#if entry.domains.length}
			<p><strong>{m.detail_domains()}:</strong> {entry.domains.join(', ')}</p>
		{/if}

		<section class="card download-box">
			{#if currentHasTransform}
				<p class="warn"><TriangleAlert size={16} /> {m.detail_transform_warning()}</p>
			{/if}
			<div class="filter-bar">
				<button class="btn btn-primary" onclick={download} disabled={!currentVersion}>
					{m.detail_download()} {#if currentVersion}({currentVersion}){/if}
				</button>
				<button class="btn" onclick={copyUrl} disabled={!currentVersion}>
					{copied ? m.detail_copied() : m.detail_copy_url()}
				</button>
				<span>{entry.installCount} {m.installs()}</span>
			</div>
			{#if downloadError}<p style="color:var(--danger-color);">{downloadError}</p>{/if}
		</section>

		<section>
			<h2>{m.detail_versions()}</h2>
			<ul class="versions">
				{#each entry.versions as v (v.semver)}
					<li class="card">
						<div class="filter-bar">
							<strong>{v.semver}</strong>
							{#if v.hasTransformCode}<Badge text={m.badge_transform()} variant="warning" icon={TriangleAlert} />{/if}
							<span style="color:var(--text-muted);">{new Date(v.submittedAt).toLocaleDateString(getLocale())}</span>
						</div>
						{#if v.changelog}<p>{v.changelog}</p>{/if}
					</li>
				{/each}
			</ul>
		</section>

		<section class="card">
			<h2>{m.report_title()}</h2>
			{#if reportSent}
				<p style="color:var(--success-color);">{m.report_thanks()}</p>
			{:else}
				<form onsubmit={submitReport}>
					<label>
						{m.report_reason()}
						<select bind:value={reportReason}>
							<option value="spam">{m.report_spam()}</option>
							<option value="broken_links">{m.report_broken_links()}</option>
							<option value="misleading">{m.report_misleading()}</option>
							<option value="legal">{m.report_legal()}</option>
						</select>
					</label>
					<label>
						{m.report_comment()}
						<textarea bind:value={reportComment} maxlength="2000" rows="3"></textarea>
					</label>
					{#if reportError}<p style="color:var(--danger-color);">{reportError}</p>{/if}
					<button class="btn" type="submit" disabled={reportSubmitting}>{m.report_submit()}</button>
				</form>
			{/if}
		</section>
	</article>
{/if}

<style>
	.detail-head {
		display: flex;
		align-items: center;
		gap: 12px;
		flex-wrap: wrap;
	}
	.detail-cats {
		display: flex;
		gap: 6px;
		flex-wrap: wrap;
		margin: 8px 0;
	}
	.screenshot {
		max-width: 100%;
		border-radius: 12px;
		border: 1px solid var(--border-color);
	}
	.download-box {
		margin: 16px 0;
	}
	.warn {
		color: var(--warning-color);
		display: flex;
		align-items: center;
		gap: 6px;
	}
	.notice {
		color: var(--warning-color);
	}
	.versions {
		list-style: none;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 8px;
	}
	form label {
		display: block;
		margin-bottom: 8px;
	}
	textarea {
		width: 100%;
	}
</style>
