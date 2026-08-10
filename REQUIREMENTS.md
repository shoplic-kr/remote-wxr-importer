# Remote WXR Importer — 요구사항 정의서

버전: 1.0 (초안)
작성일: 2026-08-10
대상 사이트: https://dev-colo.shoplic.site (WordPress 6.7.2 / PHP 8.3)

---

## 1. 개요

사용자가 워드프레스 export 파일(WXR XML)을 **원격에서 REST API로 업로드**하면, 지정한
**사용자 ID의 글로 import** 하는 플러그인. import 시 워드프레스 기본 가져오기 도구의
**"첨부 파일 내려받기와 가져오기(Download and import file attachments)"** 옵션을 항상
함께 실행하여, XML에 기록된 원격 첨부 파일 URL에서 미디어를 내려받아 미디어
라이브러리에 등록하고 본문 URL을 치환한다.

주 사용 사례: `migration/colo-*-N.xml` 처럼 글 10개 단위로 분할된 WXR 파일을
스크립트(curl 등)로 순차 업로드하여 대량 이전을 자동화한다.

## 2. 용어

| 용어 | 정의 |
|---|---|
| WXR | WordPress eXtended RSS. 워드프레스 export XML 형식 (본 프로젝트 기준 v1.2) |
| 애플리케이션 비밀번호 | 워드프레스 코어(5.6+) 내장 REST API 인증 수단. Basic Auth로 전달 |
| 대상 작성자 | API 파라미터로 전달되는 사용자 ID. import 되는 모든 글의 작성자로 지정됨 |
| 첨부 파일 가져오기 | `<wp:attachment_url>` 의 원격 파일을 내려받아 첨부(attachment) 게시물로 등록하는 동작 |

## 3. 시스템 환경 및 의존성

- WordPress ≥ 6.0 (개발/검증 기준 6.7.2), PHP ≥ 7.4 (검증 기준 8.3)
- HTTPS 필수 (애플리케이션 비밀번호는 평문 Basic Auth이므로 TLS 전제)
- **WordPress Importer(`wordpress-importer`) 플러그인 필요** — WXR 파싱과
  import 로직(`WP_Import` 클래스)을 재사용한다. 대상 사이트에 이미 설치·활성화되어 있음.
  - 비활성/미설치 시 활성화를 유도하는 오류를 반환한다 (자동 설치는 하지 않음).
- 멀티사이트는 범위 외 (단일 사이트 기준)

## 4. 기능 요구사항

### FR-1. REST API 엔드포인트

- `POST /wp-json/rwi/v1/import`
- 요청 형식: `multipart/form-data`

| 필드 | 타입 | 필수 | 기본값 | 설명 |
|---|---|---|---|---|
| `file` | file | ✔ | — | WXR XML 파일 1개. 확장자 `.xml` |
| `author_id` | int | ✔ | — | 대상 작성자 사용자 ID. 존재하는 사용자여야 함 |
| `fetch_attachments` | bool | — | `true` | 첨부 파일 내려받기 여부. 명시적으로 `false`를 보낼 때만 끔 |

- 요청당 파일 1개만 처리한다 (다중 파일 업로드는 범위 외 — 호출 측에서 반복 호출).

### FR-2. 인증·권한

- 인증: 워드프레스 코어 **애플리케이션 비밀번호** (Basic Auth). 커스텀 인증 로직을
  구현하지 않고 코어의 REST 인증 결과를 그대로 사용한다.
- 권한(`permission_callback`): 인증된 사용자가 `import` 케이퍼빌리티 보유
  (기본적으로 관리자). 미인증 401, 권한 부족 403.
- `author_id`는 인증 사용자와 달라도 된다. 단, 타인 글로 생성하는 동작이므로
  인증 사용자는 `edit_others_posts` 도 보유해야 한다.
- 쿠키/nonce 인증 경로는 지원하지 않는다 (REST 원격 호출 전용).

### FR-3. 업로드 파일 검증

- 확장자 `.xml`, MIME `text/xml`·`application/xml` 만 허용.
- 파일 크기 상한: 기본 **50MB** (`rwi_max_upload_size` 필터로 조정 가능).
  워드프레스/PHP 업로드 제한(`upload_max_filesize`, `post_max_size`)보다 크게 설정할 수 없음.
- XML 파싱 가능 여부 및 `<wp:wxr_version>` 존재를 검증. WXR 1.0~1.2 허용.
- 검증 실패 시 어떤 항목이 실패했는지 명시한 오류 반환 (7장 오류 코드 참조).
- 업로드 파일은 시스템 임시 디렉토리에만 저장하고 **import 완료·실패와 무관하게
  처리 종료 시 삭제**한다. 미디어 라이브러리나 웹 접근 가능 경로에 두지 않는다.

### FR-4. 작성자 매핑

- WXR 파일 내 `<wp:author>` 및 각 item 의 `<dc:creator>` 값과 무관하게,
  **import 되는 모든 게시물(글·첨부 포함)의 작성자를 `author_id` 로 강제 매핑**한다.
- 새 사용자를 생성하지 않는다 (importer 의 "새 사용자 만들기" 동작 비활성).
- `author_id` 사용자가 존재하지 않으면 import 를 시작하지 않고 400 반환.

### FR-5. 첨부 파일 내려받기와 가져오기

- `fetch_attachments=true`(기본)일 때 importer 의 fetch 옵션을 켜서:
  - `<wp:post_type>attachment</wp:post_type>` item 의 `<wp:attachment_url>` 에서
    파일을 내려받아 미디어 라이브러리에 등록한다.
  - 본문·미리보기 이미지(featured image, `_thumbnail_id`) 연결, 본문 내 원본 URL →
    새 URL 치환 등 워드프레스 importer 의 표준 동작을 그대로 따른다.
- 원격 다운로드 제약:
  - 파일당 다운로드 크기 상한 기본 30MB (`import_attachment_size_limit` 필터 사용).
  - 개별 파일 다운로드 실패는 **전체 import 를 중단하지 않고** 해당 항목만 실패로
    기록하여 응답의 `failed` 목록에 포함한다.
- `fetch_attachments=false`이면 attachment item 은 다운로드 없이 건너뛴다.

### FR-6. 중복 처리 (재실행 안전성)

- WordPress Importer 의 기본 정책을 따른다: 동일 게시물(제목+게시일 기준
  `post_exists`)이 이미 있으면 생성하지 않고 건너뛴다.
- 같은 파일을 두 번 업로드해도 글·첨부가 중복 생성되지 않아야 한다 (멱등성).
- 건너뛴 항목은 응답의 `skipped` 에 집계한다.

### FR-7. 응답 명세

- 성공 시 `200` + JSON:

```json
{
  "success": true,
  "file": "colo-202602-1.xml",
  "author_id": 3,
  "fetch_attachments": true,
  "imported": { "posts": 10, "attachments": 30, "terms": 4 },
  "skipped":  { "posts": 0, "attachments": 0 },
  "failed": [
    { "type": "attachment", "title": "example.png", "reason": "다운로드 실패 (404)" }
  ],
  "elapsed_ms": 42180
}
```

- 일부 항목 실패(첨부 다운로드 실패 등)여도 import 자체가 완료되면 200 을 반환하고
  `failed` 에 상세를 담는다. import 를 아예 시작하지 못한 경우만 4xx/5xx.

### FR-8. 로깅

- import 실행 단위로 로그를 남긴다: 요청 시각, 인증 사용자, 파일명, author_id,
  결과 요약(성공/건너뜀/실패 수), 소요 시간.
- 저장 위치: `error_log` 또는 `WP_DEBUG_LOG` 준수. 웹에서 접근 가능한 경로에
  로그 파일을 만들지 않는다.
- 실패 항목은 원인(HTTP 코드, WP_Error 메시지)을 포함해 기록한다.

## 5. 비기능 요구사항

- **NFR-1 (처리 시간)**: PHP `max_execution_time` 내 완료를 보장하기 위해
  요청당 권장 파일 크기는 글 10개 내외(사전 분할된 `colo-*-N.xml` 수준)로 안내한다.
  플러그인은 실행 중 `set_time_limit` 연장을 시도하되, 대용량 파일의 타임아웃
  가능성을 문서화한다. 비동기(큐) 처리는 v1 범위 외 (10장 참조).
- **NFR-2 (메모리)**: 50MB XML 기준 정상 동작. importer 의 스트리밍 파서 사용.
- **NFR-3 (보안)**:
  - 업로드 파일을 PHP 로 실행 가능한 경로에 두지 않는다.
  - XML 외부 엔티티(XXE) 비활성 상태로 파싱한다.
  - 오류 메시지에 서버 내부 경로를 노출하지 않는다.
- **NFR-4 (코딩 표준)**: WordPress Coding Standards(PHPCS) 준수, 함수·클래스
  프리픽스 `rwi_` / `RWI_`, 텍스트 도메인 `remote-wxr-importer`.
- **NFR-5 (국제화)**: 사용자 대면 문자열은 한국어 기본, i18n 함수 적용.
- **NFR-6 (제거 시)**: 플러그인 삭제 시 잔여 옵션·임시 파일을 남기지 않는다.
  import 된 콘텐츠는 삭제하지 않는다.

## 6. 사용 예시

```bash
# 애플리케이션 비밀번호 발급: 사용자 프로필 → 애플리케이션 비밀번호
curl -X POST "https://dev-colo.shoplic.site/wp-json/rwi/v1/import" \
  -u "admin:abcd efgh ijkl mnop qrst uvwx" \
  -F "file=@migration/colo-202602-1.xml" \
  -F "author_id=3"

# 분할 파일 일괄 업로드
for f in migration/colo-202607-*.xml; do
  curl -sf -X POST "https://dev-colo.shoplic.site/wp-json/rwi/v1/import" \
    -u "admin:abcd efgh ijkl mnop qrst uvwx" \
    -F "file=@$f" -F "author_id=3" || { echo "FAILED: $f"; break; }
done
```

## 7. 오류 코드

| HTTP | code | 상황 |
|---|---|---|
| 401 | `rest_not_logged_in` | 인증 정보 없음/잘못됨 (코어 처리) |
| 403 | `rwi_forbidden` | `import` 또는 `edit_others_posts` 권한 없음 |
| 400 | `rwi_missing_file` | `file` 필드 누락 |
| 400 | `rwi_missing_author` | `author_id` 누락 |
| 400 | `rwi_invalid_author` | 해당 ID 의 사용자가 없음 |
| 400 | `rwi_invalid_file_type` | 확장자/MIME 불일치 |
| 413 | `rwi_file_too_large` | 크기 상한 초과 |
| 422 | `rwi_invalid_wxr` | XML 파싱 실패 또는 WXR 형식 아님 |
| 424 | `rwi_importer_missing` | wordpress-importer 플러그인 비활성/미설치 |
| 500 | `rwi_import_failed` | import 실행 중 복구 불가 오류 |

## 8. 수용 기준 (Acceptance Criteria)

1. `colo-202602-1.xml`(글 10, 첨부 30)을 `author_id=3`으로 업로드하면:
   - 글 10개가 모두 사용자 ID 3 소유로 생성된다 (XML 의 원작성자 무시).
   - 첨부 30개가 원격 URL에서 다운로드되어 미디어 라이브러리에 등록되고,
     각 글의 대표 이미지·본문 이미지 URL이 새 사이트 URL로 치환된다.
   - 응답 JSON 의 `imported` 수치가 실제 생성 수와 일치한다.
2. 동일 파일을 재업로드하면 새 글·첨부가 생성되지 않고 `skipped` 로 집계된다.
3. 잘못된 인증 정보로 호출 시 401, `import` 권한 없는 계정(편집자 이하)으로
   호출 시 403 을 반환하며 어떤 콘텐츠도 생성되지 않는다.
4. 존재하지 않는 `author_id` 로 호출 시 400 반환, 콘텐츠 미생성.
5. WXR 이 아닌 XML 업로드 시 422 반환, 콘텐츠 미생성.
6. 첨부 원본 URL 하나가 404 인 파일을 업로드하면 나머지 항목은 정상 import 되고
   해당 항목만 `failed` 에 사유와 함께 나타난다.

## 9. 산출물

- `remote-wxr-importer/` 플러그인 (본 디렉토리)
  - `remote-wxr-importer.php` — 플러그인 부트스트랩, REST 라우트 등록
  - `includes/class-rwi-rest-controller.php` — 엔드포인트·검증·권한
  - `includes/class-rwi-importer.php` — `WP_Import` 확장 (작성자 강제 매핑, 결과 집계)
  - `readme.txt` — 사용법
- 본 요구사항 문서 (`REQUIREMENTS.md`)

## 10. v1 범위 외 (향후 고려)

- 대용량 파일 비동기(백그라운드 큐) import 및 진행률 조회 API
- 다중 파일 일괄 업로드, ZIP 업로드
- import 결과 이력 저장 및 관리자 화면(UI)
- 카테고리·태그 매핑 커스터마이즈, post_type 필터링
- 사이트 URL 치환 규칙 커스터마이즈 (search-replace)
