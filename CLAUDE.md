# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Architecture (Laravel)

Strict layered architecture — semua dalam satu folder per modul:

```
app/Modules/[Feature]/
├── [Feature]Controller.php       ← validasi request, panggil Service
├── [Feature]Service.php          ← business logic, throw HTTP exceptions
├── [Feature]Repository.php       ← SEMUA Eloquent query di sini
├── [Feature]Model.php            ← Eloquent model
├── Contracts/[Feature]RepositoryInterface.php
├── Requests/Store[Feature]Request.php
└── Resources/[Feature]Resource.php
```

- Eloquent queries **hanya** di `*Repository.php` — dilarang di Service.
- Service throw Laravel HTTP exceptions — Controller tidak pernah catch & re-throw.
- Repository wajib implement interface dari `Contracts/`.
- Multi-tenant: semua query scope ke `id_perusahaan`.
- Auth: Laravel Sanctum stateless API tokens (`Authorization: Bearer <token>`).
