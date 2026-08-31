# CRM wireframes (Phase 0)

Desktop-primary. Sidebar + content.

## Shell

```
┌──────────┬──────────────────────────────────────────────┐
│ Logo     │  Search…                        Admin ▾      │
│──────────┤──────────────────────────────────────────────│
│ Dashboard│  Page title                    [ Primary ]   │
│ Customers│                                              │
│ Subs     │  filters / table / detail                    │
│ Keys     │                                              │
│ Devices  │                                              │
│ Locations│                                              │
│ Servers  │                                              │
│ Sessions │                                              │
│ Payments │                                              │
│ Support  │                                              │
│ Reports  │                                              │
│ Admins   │                                              │
│ Audit    │                                              │
│ Settings │                                              │
└──────────┴──────────────────────────────────────────────┘
```

## Dashboard

```
┌ Active Customers ┐ ┌ Active Subs ┐ ┌ Connected ┐ ┌ Nodes Online ┐
│ 12,420           │ │ 11,870      │ │ 3,425     │ │ 18 / 19      │
└──────────────────┘ └─────────────┘ └───────────┘ └──────────────┘

Expiring soon | Node health | Recent payments | Alerts
```

## Customer detail

```
CUST-000125 · Aung Myo · ACTIVE
[Renew] [Change Plan] [Generate Key] [Reset Device] [Suspend]

Tabs: Overview | Subscription | Devices | Keys | Sessions | Payments | Audit

Overview:
  Plan Premium · Expiry 25 Sep 2026 · Devices 1/2 · Sessions 1
```

## Create customer wizard (result step)

```
Customer created

Customer ID:     CUST-000125     [Copy]
Activation Key:  VPN-7KQ2-F9PX-W3MT  [Copy]

[Print] [Download card] [Done]

Note: full key shown once at creation.
```

## VPN servers (NOC)

```
Location | Node | Status | Sessions | Capacity | CPU | Mem | Traffic | Heartbeat | Actions
Bangkok  | BKK-01 | Healthy | 120 | 80% | … | … | … | 2s ago | View Drain Maint
```
