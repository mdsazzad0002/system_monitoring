@extends('master')
@section('title', 'Subscription')
@section('breadcrumb', 'Subscription')

@push('style')
<style>
    .subscription-wrap {
        max-width: 760px;
        margin: 0 auto;
    }

    .subscription-panel {
        background: #111827;
        color: #e5e7eb;
        border: 1px solid #243041;
        border-radius: 20px;
        padding: 24px;
        box-shadow: 0 18px 50px rgba(2, 6, 23, 0.28);
    }

    .subscription-muted {
        color: #94a3b8;
    }

    .subscription-meta {
        display: grid;
        gap: 12px;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        margin: 20px 0;
    }

    .subscription-meta-item {
        background: #0f172a;
        border: 1px solid #243041;
        border-radius: 14px;
        padding: 14px 16px;
    }

    .subscription-label {
        display: block;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #94a3b8;
        margin-bottom: 4px;
    }

    .subscription-value {
        font-size: 16px;
        font-weight: 700;
        color: #f8fafc;
        word-break: break-word;
    }

    .subscription-status {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
    }

    .subscription-status.active {
        background: rgba(34, 197, 94, 0.14);
        color: #4ade80;
    }

    .subscription-status.expired {
        background: rgba(239, 68, 68, 0.16);
        color: #f87171;
    }

    .subscription-status.missing {
        background: rgba(245, 158, 11, 0.16);
        color: #fbbf24;
    }

    .subscription-status.service-expired {
        background: rgba(249, 115, 22, 0.16);
        color: #fb923c;
    }
</style>
@endpush

@section('content')
<div class="subscription-wrap">
    <div class="subscription-panel">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-1">Subscription</h1>
                <p class="subscription-muted mb-0">Update the license key and check the expiry date.</p>
            </div>
            <span id="statusBadge" class="subscription-status missing">Loading...</span>
        </div>

        <div class="subscription-meta">
            <div class="subscription-meta-item">
                <span class="subscription-label">Subscription Type</span>
                <div id="subscriptionType" class="subscription-value">-</div>
            </div>
            <div class="subscription-meta-item">
                <span class="subscription-label">Expire Date</span>
                <div id="expiresAt" class="subscription-value">-</div>
            </div>
            <div class="subscription-meta-item">
                <span class="subscription-label">Maintenance End Date</span>
                <div id="maintenanceEndAt" class="subscription-value">-</div>
            </div>
            <div class="subscription-meta-item">
                <span class="subscription-label">Status</span>
                <div id="licenseStatus" class="subscription-value">-</div>
            </div>
            <div class="subscription-meta-item">
                <span class="subscription-label">Request Domain</span>
                <div id="requestDomain" class="subscription-value">-</div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">License Key</label>
            <input type="text" class="form-control" id="licenseInput" placeholder="Enter license key">
        </div>

        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary" id="saveBtn">Save License</button>
            <button class="btn btn-outline-light" id="refreshBtn" type="button">Refresh</button>
        </div>

        <div class="mt-3 subscription-muted" id="messageText">Loading...</div>
    </div>
</div>
@endsection

@push('js')
<script>
const endpoints = {
    data: '/subscription/data',
    save: '/subscription/save'
};

const statusBadge = document.getElementById('statusBadge');
const subscriptionType = document.getElementById('subscriptionType');
const expiresAt = document.getElementById('expiresAt');
const maintenanceEndAt = document.getElementById('maintenanceEndAt');
const licenseStatus = document.getElementById('licenseStatus');
const requestDomain = document.getElementById('requestDomain');
const licenseInput = document.getElementById('licenseInput');
const saveBtn = document.getElementById('saveBtn');
const refreshBtn = document.getElementById('refreshBtn');
const messageText = document.getElementById('messageText');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

function setStatus(status) {
    statusBadge.className = 'subscription-status ' + status;
    statusBadge.textContent = status === 'active'
        ? 'Active'
        : status === 'service-expired'
            ? 'Maintenance Expired'
        : status === 'expired'
            ? 'Expired'
            : 'Missing';
}

function setBusy(isBusy, label) {
    saveBtn.disabled = isBusy;
    refreshBtn.disabled = isBusy;
    saveBtn.textContent = isBusy ? 'Saving...' : 'Save License';
    messageText.textContent = label || '';
}

async function loadSubscription() {
    setBusy(true, 'Loading subscription...');
    try {
        const browserDomain = window.location.host || window.location.hostname || '';
        const response = await fetch(`${endpoints.data}?domain=${encodeURIComponent(browserDomain)}`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        licenseInput.value = data.license ?? '';
        subscriptionType.textContent = data.subscription_type ?? 'Not available';
        expiresAt.textContent = data.expires_at_label ?? data.expires_at ?? 'Not available';
        maintenanceEndAt.textContent = data.maintenance_end_date_label ?? data.maintenance_end_date ?? 'Not available';
        requestDomain.textContent = data.request_domain ?? browserDomain ?? 'Not available';
        licenseStatus.textContent = data.service_entitlement === false
            ? 'maintenance_expired'
            : (data.license_effective_status ?? (data.license_reason ?? 'unknown'));

        if (data.license_reason === 'expired_license') {
            setStatus('expired');
        } else if (data.license_reason === 'missing_license') {
            setStatus('missing');
        } else if (data.service_entitlement === false) {
            setStatus('service-expired');
        } else {
            setStatus('active');
        }

        messageText.textContent = data.message || '';
    } catch (error) {
        messageText.textContent = String(error);
        setStatus('missing');
    } finally {
        setBusy(false, messageText.textContent);
    }
}

async function saveLicense() {
    setBusy(true, 'Saving license...');
    try {
        const form = new FormData();
        form.append('license', licenseInput.value.trim());
        form.append('domain', window.location.host || window.location.hostname || '');

        const response = await fetch(endpoints.save, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: form
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Unable to save license.');
        }

        messageText.textContent = data.message || 'License updated.';
        await loadSubscription();
    } catch (error) {
        messageText.textContent = String(error);
    } finally {
        setBusy(false, messageText.textContent);
    }
}

saveBtn.addEventListener('click', saveLicense);
refreshBtn.addEventListener('click', loadSubscription);

loadSubscription();
</script>
@endpush
