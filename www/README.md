# PQService — новая веб-версия

Новая версия сайта находится в отдельном каталоге `www/`. Старые каталоги `pqservice/`, `zip/` и корневые файлы проекта не изменяются.

## Структура

- `index.html` — публичная гостевая страница.
- `login.html` — единая форма входа.
- `account.html` — личный кабинет пользователя.
- `admin.html` — панель администратора.
- `assets/css/app.css` — единая дизайн-система и адаптивная вёрстка.
- `assets/js/app.js` — маршрутизация интерфейса, API-клиент и обработчики страниц.

## Текущий этап

Реализован новый frontend-каркас и светлый адаптивный дизайн. Страницы используют единый визуальный язык и отдельные зоны доступа.

Frontend ожидает серверные endpoints, перечисленные в `assets/js/app.js`, в том числе:

- `GET /api/session`
- `POST /api/login`
- `POST /api/logout`
- `GET /api/profile`
- `GET /api/transactions`
- `GET /api/programs`
- `GET /api/admin/overview`
- `GET /api/admin/users`
- `GET /api/admin/user/:uuid`
- `POST /api/admin/user/update`
- `POST /api/admin/coins/credit`
- `GET /api/admin/programs`
- `POST /api/admin/programs`
- `GET /api/admin/promocodes`
- `POST /api/admin/promocodes`
- `GET /api/admin/xui/status`
- `POST /api/admin/xui/sync`

Это контракт frontend для следующего этапа интеграции, а не утверждение, что существующий backend уже реализует все endpoints.

## Развёртывание

Каталог `www/` предполагается назначить document root сайта на сервере после подготовки и проверки backend, rewrite/маршрутов и безопасной конфигурации. Пока не переключайте production document root на этот каталог: текущий frontend ещё не подключён к рабочей серверной авторизации и API.

Не размещайте в `www/` SQLite базы, конфигурацию 3x-ui, пароли, API-токены, резервные копии или другие секреты.
