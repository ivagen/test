# Редагований список

Невеликий редагований список: перегляд, додавання, перейменування та видалення елементів,
причому зміни поширюються на інші відкриті сесії браузера в реальному часі.

Спочатку це було демо 2017 року на Yii 2 / AngularJS. Проєкт повністю відроджено: кожен
компонент, що досяг кінця життєвого циклу, замінено, дані збережено, а весь застосунок тепер
відтворюваний, покритий тестами й безпечний за замовчуванням.

---

## Архітектура

```text
browser
  ├── GET/POST/PUT/DELETE  /api/items ──┐
  └── WS/WSS               /ws ─────┐   │
                                    v   v
                            nginx (the only published port)
                                │       │
                                v       v
                          websocket   php-fpm (Yii 2)
                           worker       │
                             ^          ├──> PostgreSQL 17   (source of truth)
                             └── Redis 8 <── event published after each commit
```

**PostgreSQL — єдине джерело істини.** Події WebSocket є підказками з доставкою «за
можливості», які дозволяють клієнту швидко оновитися; під час підключення, після обриву
з'єднання або отримання будь-чого, що не вдалося розібрати, клієнт повторно запитує
`GET /api/items`. Тому збій реального часу погіршує зручність, але ніколи не блокує
додавання, перейменування чи видалення.

| Компонент                | Версія                                     |
| ------------------------ | ------------------------------------------ |
| PHP                      | 8.4 (`php:8.4.23-fpm-alpine3.24`)          |
| Yii                      | 2.0.55                                     |
| PostgreSQL               | 17.10                                      |
| Redis                    | 8.8.1                                      |
| nginx                    | 1.31.2                                     |
| Node.js (інструментарій) | 24 LTS (`node:24.18.0-bookworm`)           |
| WebSocket-сервер         | Workerman 5.2                              |
| Браузерний клієнт        | TypeScript + Vite, без runtime-залежностей |

Кожен образ закріплено тегом **і** дайджестом у [`compose.yaml`](compose.yaml).
Підтримуються як `linux/amd64`, так і `linux/arm64`.

---

## Передумови

- Docker Engine / Docker Desktop із Compose v2 або новішим
- Git

PHP, Composer, Node, PostgreSQL чи Redis на хості не потрібні — усе працює в контейнерах.

---

## Чисте встановлення

```sh
cp .env.example .env
docker compose config
docker compose build --no-cache
docker compose up -d --wait
docker compose exec app php yii migrate --interactive=0
```

Або те саме однією командою:

```sh
make setup
```

Далі відкрийте **<http://localhost:8080>**. Якщо порт 8080 зайнятий, змініть `APP_PORT`
у файлі `.env`.

На хост опубліковано лише nginx. PostgreSQL і Redis доступні тільки зсередини мережі
проєкту.

### Конфігурація

Уся конфігурація задається через змінні середовища; повний перелік див. у
[`.env.example`](.env.example). Значення для розробки безпечно використовувати локально, і
вони **відхиляються** при `APP_ENV=prod`, тож продакшн-розгортання не може випадково
стартувати з прикладовими секретами.

---

## Щоденні команди

```sh
make up                 # запустити все й дочекатися стану healthy
make logs               # стежити за логами app, websocket і nginx
make migrate            # застосувати незастосовані міграції
make ps                 # стан сервісів
make down               # зупинити контейнери проєкту (дані ЗБЕРІГАЮТЬСЯ)
make shell              # оболонка всередині контейнера застосунку
make psql               # сесія psql
```

`make help` виводить перелік усіх цілей.

### Скидання

```sh
make reset              # запитує підтвердження, потім: docker compose down --volumes
```

> **Це видаляє том PostgreSQL цього проєкту й усі збережені в ньому елементи.**
> Команду навмисно відокремлено від `make down`, яка зберігає ваші дані.
> Жодна команда в цьому репозиторії не зачіпає контейнери, образи, мережі чи томи Docker,
> що належать чомусь іншому. (Скрипт `docker/rmi.sh` зразка 2017 року виконував
> `docker rm $(docker ps -a -q)` та `docker rmi $(docker images -q)`, що стирало весь хост.
> Його видалено.)

---

## Тестування та перевірка

Одна команда запускає все те саме, що й CI:

```sh
make verify
```

Вона охоплює: конфігурацію та контракт Compose, коректність YAML/JSON, сканування на
заборонені застарілі компоненти, `composer validate --strict`, PHPUnit (модульні,
контрактні, міграційні тести, перевірку паритету з легасі), PHPStan, PHP-CS-Fixer, ESLint,
`tsc`, Vitest, Playwright і обидва аудити залежностей.

Окремі рівні:

```sh
make test-backend       # PHPUnit
make test-frontend      # Vitest
make e2e                # Playwright
make lint               # ESLint + tsc
make analyse            # PHPStan
make style              # PHP-CS-Fixer (style-fix — щоб застосувати)
make audit              # composer audit + npm audit
make scan               # заборонені застарілі компоненти, репозиторій і запущений стек
```

Перевірки, які потребують керування самими контейнерами — збої залежностей, обробка
SIGTERM, відповіді про помилки в продакшн-режимі, збереження даних при перестворенні:

```sh
make acceptance
```

---

## API

`GET` є відкритим; кожна мутація потребує CSRF-токена, який сторінка публікує в тегу
`<meta name="csrf-token">` і який повертається в заголовку `X-CSRF-Token`. Повний контракт —
[`contracts/openapi.yaml`](contracts/openapi.yaml).

| Метод    | Шлях              | Успіх                   | Помилки                    |
| -------- | ----------------- | ----------------------- | -------------------------- |
| `GET`    | `/api/items`      | `200 {"items":[…]}`     | —                          |
| `POST`   | `/api/items`      | `201` + елемент         | `403`, `415`, `422`        |
| `PUT`    | `/api/items/{id}` | `200` + елемент         | `403`, `404`, `415`, `422` |
| `DELETE` | `/api/items/{id}` | `204` (без тіла)        | `403`, `404`               |
| `GET`    | `/healthz`        | `200` liveness          | —                          |
| `GET`    | `/readyz`         | `200` / `503` readiness | —                          |

Помилки завжди використовують однакову оболонку:
`{"code": "...", "message": "...", "details": {...}}`, де `code` є стабільним і придатним
для машинної обробки. Жодна відповідь ніколи не містить стек викликів, шлях у файловій
системі, SQL або обліковий запис.

### Відтворювана сесія curl

```sh
BASE=http://localhost:8080

# Fetch the CSRF token and keep the cookie it is validated against.
TOKEN=$(curl -sS -c /tmp/cookies "$BASE/" \
  | grep -o 'name="csrf-token" content="[^"]*"' | sed 's/.*content="//; s/"$//')

# List
curl -sS "$BASE/api/items"

# Create -> 201
ID=$(curl -sS -b /tmp/cookies -X POST "$BASE/api/items" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  -d '{"name":"Milk"}' | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')

# Rename -> 200
curl -sS -b /tmp/cookies -X PUT "$BASE/api/items/$ID" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  -d '{"name":"Bread"}'

# Invalid input -> 422 with code, message and details
curl -sS -b /tmp/cookies -X POST "$BASE/api/items" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" -d '{"name":"   "}'

# Missing CSRF token -> 403
curl -sS -X POST "$BASE/api/items" -H 'Content-Type: application/json' -d '{"name":"x"}'

# Unknown id -> 404
curl -sS -b /tmp/cookies -X DELETE "$BASE/api/items/999999" -H "X-CSRF-Token: $TOKEN"

# Delete -> 204
curl -sS -o /dev/null -w '%{http_code}\n' -b /tmp/cookies -X DELETE "$BASE/api/items/$ID" \
  -H "X-CSRF-Token: $TOKEN"
```

### Події реального часу

Браузер підключається до `/ws` на власному origin сторінки (`wss` — автоматично, коли
сторінку віддано через HTTPS). Кожна зафіксована мутація публікує одну типізовану подію:

```json
{
  "eventId": "6db69aac-0f19-47f0-a24c-c57af9cf0d16",
  "type": "item.updated",
  "itemId": 12,
  "item": { "id": 12, "name": "Updated name" },
  "occurredAt": "2026-07-25T15:00:00.000Z"
}
```

Доставка здійснюється «за можливості» й може дублювати або губити повідомлення; клієнт
усуває дублікати за `eventId` і робить повторний запит щоразу, коли не впевнений. Повні
правила: [`contracts/websocket-events.md`](contracts/websocket-events.md).

---

## Міграція з API 2017 року

Старі маршрути через рядок запиту **видалено**, а не залишено за адаптером: власної бізнес-
логіки вони не містили, зовнішніх споживачів не мали, і кожен із них був точкою мутації без
захисту CSRF.

| 2017                                                          | Тепер                                                       |
| ------------------------------------------------------------- | ----------------------------------------------------------- |
| `GET /index.php?r=site/get` → `{"rows":[…]}`                  | `GET /api/items` → `{"items":[…]}`                          |
| `POST /index.php?r=site/create`, form-encoded `Items[name]`   | `POST /api/items`, JSON `{"name":…}`                        |
| `PUT /index.php?r=site/update&id=N`                           | `PUT /api/items/{id}`                                       |
| `DELETE /index.php?r=site/delete&id=N`                        | `DELETE /api/items/{id}`                                    |
| `ws://host:8047/websocket`, увесь список надсилався щосекунди | `/ws` на тому самому origin, одна типізована подія на зміну |
| помилка валідації → `400 {"error":{…}}`                       | → `422 {"code","message","details"}`                        |
| захисту CSRF немає                                            | `X-CSRF-Token` обов'язковий для всіх мутацій                |

**Збережені дані не зачеплено.** Таблицю `items` не видаляють і не створюють заново;
створює її досі та сама початкова міграція. Тест наповнює базу даних за схемою 2017 року,
мігрує її та перевіряє, що кожен id і кожна назва зберігаються побайтово — включно з
назвами в Unicode, назвою на 255 символів та неперервними id.

---

## Нотатки для продакшену

- Встановіть `APP_ENV=prod`. Налагоджувальний вивід вимкнено, і застосунок **відмовляється
  стартувати** з плейсхолдером замість секрету.
- Згенеруйте справжні секрети: `openssl rand -base64 32` для
  `APP_COOKIE_VALIDATION_KEY` та надійний `POSTGRES_PASSWORD`.
- Термінуйте TLS на зворотному проксі перед nginx і передавайте `X-Forwarded-Proto`. Клієнт
  визначає `wss://` з origin сторінки автоматично; налаштовувати нічого не потрібно.
- Публікувати слід лише порт nginx. PostgreSQL і Redis залишаються у внутрішній мережі.
- Redis не зберігає довготривалого стану (`--save "" --appendonly no`). Його повна втрата не
  коштує нічого, крім короткої паузи в реальному часі.
- Встановлення без інструментів розробки: при `APP_ENV=prod` точка входу виконує
  `composer install --no-dev --optimize-autoloader`.
- Спрямуйте liveness-пробу вашого оркестратора на `/healthz`, а readiness-пробу — на
  `/readyz`.
- Логи — це структурований JSON у stdout/stderr із приховуванням відомих секретів.

---

## Усунення несправностей

**Порт 8080 уже зайнято** — змініть `APP_PORT` у `.env` та виконайте `make up`.

**Сторінка повідомляє, що бандл не зібрано** — контейнер frontend збирає ресурси під час
запуску; `docker compose logs frontend` покаже причину збою. Пересобрати:
`docker compose exec frontend npm run build`.

**`app` ніколи не стає healthy** — він чекає на PostgreSQL і Redis, а потім під час першого
запуску встановлює залежності Composer. `docker compose logs app` показує поступ;
`docker compose exec app php yii health/check` повідомляє, яка саме залежність відмовляє.

**Залежності виглядають застарілими після зміни `composer.json` чи `package.json`** — точки
входу перевстановлюють їх автоматично, коли змінюється хеш lock-файлу. Щоб зробити це
примусово: `docker compose exec app composer install` або
`docker compose exec frontend npm ci`.

**Оновлення в реальному часі припинилися** — сторінка показує банер про погіршений режим, а
CRUD продовжує працювати. Перевірте `docker compose logs websocket`; воркер самостійно
перепідключається до Redis з обмеженим зростанням затримки.

**Куди подівся Adminer?** — стек 2017 року містив переглядач бази даних на
`php:5.6-apache` (кінець життєвого циклу з 2018 року), який завантажував
`adminer.org/latest.php` під час збірки й публікував власний порт на хості. Його видалено, а
не закріплено: він існував лише для перегляду бази, `make psql` робить те саме без EOL-
рантайму, а кожен додатковий опублікований порт суперечить правилу «ззовні доступний лише
проксі». Якщо потрібен GUI, запустіть його на власній машині й прокиньте тунель до
контейнера — у цьому репозиторії нічого змінювати не треба.

**Усе заплутано** — `make reset` пересобирає все з нуля. Він видаляє том бази даних цього
проєкту й нічого більше.
