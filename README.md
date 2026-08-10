# Remote WXR Importer

[English](#english) | [한국어](#한국어)

## English

Remote WXR Importer imports WordPress eXtended RSS (WXR) XML files through an authenticated REST API request. Every imported post, menu item, and attachment is assigned to a specified existing WordPress user.

### Features

- Provides `POST /wp-json/rwi/v1/import` for remote WXR imports.
- Supports WXR versions 1.0 through 1.2.
- Uses WordPress Application Password authentication.
- Assigns imported content to the user specified by `author_id`, regardless of the authors recorded in the WXR file.
- Optionally downloads remote attachments and preserves the WordPress Importer content and featured-image mappings.
- Reports imported, skipped, and failed items in a JSON response.
- Validates file extension, MIME type, size, XML structure, and WXR version.
- Rejects XML documents containing a DTD and disables external network access while parsing.
- Removes staged WXR files after every request, including failed requests.

### Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- HTTPS
- The official [WordPress Importer](https://wordpress.org/plugins/wordpress-importer/) plugin, installed and activated
- A WordPress user with an Application Password and both the `import` and `edit_others_posts` capabilities

This plugin targets single-site WordPress installations. Multisite and background processing are outside the current scope.

### Installation

1. Install and activate the official WordPress Importer plugin.
2. Upload the `remote-wxr-importer` directory to `wp-content/plugins/`, or install the packaged ZIP from the WordPress Plugins screen.
3. Activate Remote WXR Importer.
4. Open the profile of an administrator account and create an Application Password.
5. Send API requests over HTTPS.

### API request

Send a `multipart/form-data` request to:

```text
POST /wp-json/rwi/v1/import
```

| Field | Required | Default | Description |
|---|---:|---:|---|
| `file` | Yes | — | One WXR 1.0–1.2 XML file. The extension must be `.xml`, and the MIME type must be `text/xml` or `application/xml`. |
| `author_id` | Yes | — | ID of an existing WordPress user who will own all imported content. |
| `fetch_attachments` | No | `true` | Set to `false` to skip remote attachment downloads. |

Example:

```bash
curl -X POST "https://example.com/wp-json/rwi/v1/import" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -F "file=@migration/export-1.xml;type=application/xml" \
  -F "author_id=3" \
  -F "fetch_attachments=true"
```

Do not use a normal account password. Create a dedicated Application Password and revoke it when it is no longer needed.

### Successful response

The endpoint returns HTTP 200 after a completed import. Individual item failures do not abort the whole import and appear in the `failed` array.

```json
{
  "success": true,
  "file": "export-1.xml",
  "author_id": 3,
  "fetch_attachments": true,
  "imported": {
    "posts": 10,
    "attachments": 30,
    "terms": 4
  },
  "skipped": {
    "posts": 0,
    "attachments": 0
  },
  "failed": [],
  "elapsed_ms": 42180
}
```

### Error responses

Fatal validation or import failures use the standard WordPress REST error format.

| HTTP status | Code | Meaning |
|---:|---|---|
| 401 | `rest_not_logged_in` | Application Password authentication is missing or invalid. |
| 403 | `rwi_forbidden` | The authenticated user lacks `import` or `edit_others_posts`. |
| 400 | `rwi_missing_file` | The `file` field is missing. |
| 400 | `rwi_missing_author` | The `author_id` field is missing. |
| 400 | `rwi_invalid_author` | `author_id` is invalid or does not identify an existing user. |
| 400 | `rwi_invalid_file_type` | The extension or MIME type is not allowed. |
| 413 | `rwi_file_too_large` | The uploaded file exceeds the effective size limit. |
| 422 | `rwi_invalid_wxr` | The XML is invalid or is not a supported WXR document. |
| 424 | `rwi_importer_missing` | The official WordPress Importer is missing, inactive, or incomplete. |
| 500 | `rwi_import_failed` | An unrecoverable import error occurred. |

### Limits and operation

- The WXR upload limit is 50 MB by default. Adjust it with the `rwi_max_upload_size` filter. The effective value cannot exceed WordPress or PHP upload limits.
- Remote attachments are limited to 30 MB per file by default. Adjust this with the WordPress Importer `import_attachment_size_limit` filter.
- The plugin attempts to remove PHP's execution time limit, but server or proxy timeouts may still terminate a long request.
- For large migrations, split the export into files containing approximately 10 posts and import them sequentially.
- Reimported posts follow the WordPress Importer duplicate-detection policy and are reported under `skipped`.

### Logging, privacy, and removal

One summary line is written for each import to the PHP `error_log`, or to `WP_DEBUG_LOG` when WordPress debug logging is configured. The plugin does not create its own public log, options, or history records.

Staged XML files are stored in the system temporary directory and removed after processing. Uninstalling the plugin removes matching leftover temporary files but does not delete imported content.

---

## 한국어

Remote WXR Importer는 인증된 REST API 요청을 통해 WordPress eXtended RSS(WXR) XML 파일을 가져옵니다. 가져온 모든 글, 메뉴 항목, 첨부 파일의 작성자는 요청에서 지정한 기존 워드프레스 사용자로 설정됩니다.

### 주요 기능

- 원격 WXR 가져오기를 위한 `POST /wp-json/rwi/v1/import` 엔드포인트 제공
- WXR 1.0~1.2 지원
- 워드프레스 애플리케이션 비밀번호 인증 사용
- WXR에 기록된 작성자와 관계없이 `author_id`로 지정한 사용자에게 모든 콘텐츠 할당
- 원격 첨부 파일을 선택적으로 내려받고 WordPress Importer의 본문 URL 및 대표 이미지 매핑 유지
- 생성, 중복 건너뜀, 실패 항목을 JSON으로 반환
- 파일 확장자, MIME 형식, 크기, XML 구조, WXR 버전 검증
- DTD가 포함된 XML을 거부하고 파싱 중 외부 네트워크 접근 차단
- 실패 여부와 관계없이 요청이 끝날 때 임시 WXR 파일 삭제

### 요구 사항

- WordPress 6.0 이상
- PHP 7.4 이상
- HTTPS
- 공식 [WordPress Importer](https://wordpress.org/plugins/wordpress-importer/) 플러그인 설치 및 활성화
- 애플리케이션 비밀번호와 `import`, `edit_others_posts` 권한을 모두 가진 워드프레스 사용자

이 플러그인은 단일 사이트용입니다. 멀티사이트와 백그라운드 처리는 현재 범위에 포함되지 않습니다.

### 설치

1. 공식 WordPress Importer 플러그인을 설치하고 활성화합니다.
2. `remote-wxr-importer` 디렉터리를 `wp-content/plugins/`에 업로드하거나, 워드프레스 플러그인 화면에서 배포 ZIP을 설치합니다.
3. Remote WXR Importer를 활성화합니다.
4. 관리자 계정의 프로필에서 애플리케이션 비밀번호를 만듭니다.
5. HTTPS를 통해 API 요청을 전송합니다.

### API 요청

다음 엔드포인트에 `multipart/form-data` 요청을 보냅니다.

```text
POST /wp-json/rwi/v1/import
```

| 필드 | 필수 | 기본값 | 설명 |
|---|---:|---:|---|
| `file` | 예 | — | WXR 1.0~1.2 XML 파일 한 개. 확장자는 `.xml`, MIME 형식은 `text/xml` 또는 `application/xml`이어야 합니다. |
| `author_id` | 예 | — | 가져온 모든 콘텐츠의 소유자가 될 기존 워드프레스 사용자 ID입니다. |
| `fetch_attachments` | 아니요 | `true` | 원격 첨부 파일을 건너뛰려면 `false`로 설정합니다. |

예시:

```bash
curl -X POST "https://example.com/wp-json/rwi/v1/import" \
  -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -F "file=@migration/export-1.xml;type=application/xml" \
  -F "author_id=3" \
  -F "fetch_attachments=true"
```

일반 계정 비밀번호를 사용하지 마세요. 전용 애플리케이션 비밀번호를 만들고 더 이상 필요하지 않을 때 폐기하세요.

### 성공 응답

가져오기가 완료되면 HTTP 200을 반환합니다. 개별 항목 실패는 전체 가져오기를 중단하지 않으며 `failed` 배열에 포함됩니다.

```json
{
  "success": true,
  "file": "export-1.xml",
  "author_id": 3,
  "fetch_attachments": true,
  "imported": {
    "posts": 10,
    "attachments": 30,
    "terms": 4
  },
  "skipped": {
    "posts": 0,
    "attachments": 0
  },
  "failed": [],
  "elapsed_ms": 42180
}
```

### 오류 응답

검증 실패나 치명적인 가져오기 오류는 워드프레스 REST 표준 오류 형식으로 반환됩니다.

| HTTP 상태 | 코드 | 의미 |
|---:|---|---|
| 401 | `rest_not_logged_in` | 애플리케이션 비밀번호 인증이 없거나 올바르지 않습니다. |
| 403 | `rwi_forbidden` | 인증 사용자에게 `import` 또는 `edit_others_posts` 권한이 없습니다. |
| 400 | `rwi_missing_file` | `file` 필드가 없습니다. |
| 400 | `rwi_missing_author` | `author_id` 필드가 없습니다. |
| 400 | `rwi_invalid_author` | `author_id`가 올바르지 않거나 기존 사용자를 가리키지 않습니다. |
| 400 | `rwi_invalid_file_type` | 확장자 또는 MIME 형식이 허용되지 않습니다. |
| 413 | `rwi_file_too_large` | 업로드 파일이 실제 크기 제한을 초과했습니다. |
| 422 | `rwi_invalid_wxr` | XML이 올바르지 않거나 지원하는 WXR 문서가 아닙니다. |
| 424 | `rwi_importer_missing` | 공식 WordPress Importer가 없거나 비활성 상태이거나 파일이 불완전합니다. |
| 500 | `rwi_import_failed` | 복구할 수 없는 가져오기 오류가 발생했습니다. |

### 제한 및 운영 안내

- WXR 업로드 제한은 기본 50MB이며 `rwi_max_upload_size` 필터로 조정할 수 있습니다. 실제 값은 워드프레스 또는 PHP 업로드 제한을 초과할 수 없습니다.
- 원격 첨부 파일은 기본적으로 파일당 30MB까지 허용됩니다. WordPress Importer의 `import_attachment_size_limit` 필터로 조정할 수 있습니다.
- 플러그인은 PHP 실행 시간 제한 해제를 시도하지만 서버나 프록시 타임아웃으로 긴 요청이 종료될 수 있습니다.
- 대규모 이전은 export를 글 약 10개 단위의 파일로 나누어 순차 실행하는 방식을 권장합니다.
- 같은 글을 다시 가져오면 WordPress Importer의 중복 감지 정책에 따라 `skipped`로 집계됩니다.

### 로그, 개인정보 및 제거

가져오기마다 요약 한 줄을 PHP `error_log`에 기록하며, 워드프레스 디버그 로그가 설정된 경우 `WP_DEBUG_LOG`를 따릅니다. 플러그인은 공개 로그 파일, 옵션 또는 이력 레코드를 별도로 만들지 않습니다.

임시 XML 파일은 시스템 임시 디렉터리에 저장되며 처리 후 삭제됩니다. 플러그인을 제거하면 일치하는 잔여 임시 파일을 삭제하지만 가져온 콘텐츠는 삭제하지 않습니다.
