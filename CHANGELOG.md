# Changelog

## [0.6.0](https://github.com/getmilpa/tool-runtime/compare/v0.5.2...v0.6.0) (2026-07-14)


### Features

* ToolContext::web + unknown channel fails closed ([62f0877](https://github.com/getmilpa/tool-runtime/commit/62f0877096094de1676740e80f8a3ae508f6bbb0))

## [0.5.2](https://github.com/getmilpa/tool-runtime/compare/v0.5.1...v0.5.2) (2026-07-12)


### Bug Fixes

* receive milpa/core 0.6 — pin bump ([0683c0f](https://github.com/getmilpa/tool-runtime/commit/0683c0f0003a9f282d3ba828a934ce174dff8f28))

## [0.5.1](https://github.com/getmilpa/tool-runtime/compare/v0.5.0...v0.5.1) (2026-07-09)


### Features

* object-shaped tool parameters via #[Param(type: object)] ([4f75d6b](https://github.com/getmilpa/tool-runtime/commit/4f75d6b62b6aa7fb179f8bb14d84ea8caabdc312))


### Miscellaneous Chores

* release 0.5.1 ([6ca7425](https://github.com/getmilpa/tool-runtime/commit/6ca742572961a7952103564a38968a241a7503a3))

## [0.5.0](https://github.com/getmilpa/tool-runtime/compare/v0.4.0...v0.5.0) (2026-07-08)


### ⚠ BREAKING CHANGES

* tool lifecycle events — tool.executing (interceptable) / executed / failed

### Features

* tool lifecycle events — tool.executing (interceptable) / executed / failed ([f6c2878](https://github.com/getmilpa/tool-runtime/commit/f6c2878f5b4badc794ee4deaf4df03789564316c))

## [0.4.0](https://github.com/getmilpa/tool-runtime/compare/v0.3.0...v0.4.0) (2026-07-08)


### ⚠ BREAKING CHANGES

* resolve verifications by request id (core 0.4 seam)

### Features

* resolve verifications by request id (core 0.4 seam) ([368fe8f](https://github.com/getmilpa/tool-runtime/commit/368fe8fa7b81e8e694503ca0387f51e9aa69db69))

## [0.3.0](https://github.com/getmilpa/tool-runtime/compare/v0.2.1...v0.3.0) (2026-07-08)


### ⚠ BREAKING CHANGES

* split verification into request/resolve tools, stdio context, self-explaining denials

### Features

* split verification into request/resolve tools, stdio context, self-explaining denials ([2107455](https://github.com/getmilpa/tool-runtime/commit/21074552674cceae190bb718a6a351f7572465b6))

## [0.2.1](https://github.com/getmilpa/tool-runtime/compare/v0.2.0...v0.2.1) (2026-07-08)


### Bug Fixes

* serialize empty properties as a JSON object, not an array ([1873966](https://github.com/getmilpa/tool-runtime/commit/18739668590673a725cd0a18a8e7743cfee0060c))

## [0.2.0](https://github.com/getmilpa/tool-runtime/compare/v0.1.0...v0.2.0) (2026-07-07)


### ⚠ BREAKING CHANGES

* direct human_verify (no double gate) + typed tool listings

### Features

* direct human_verify (no double gate) + typed tool listings ([6442fb3](https://github.com/getmilpa/tool-runtime/commit/6442fb3ed92e8a76b085571a2d375585f10b1ac8))


### Bug Fixes

* **docs:** family-coherent links + footer credit color on the docs site ([d0b1031](https://github.com/getmilpa/tool-runtime/commit/d0b10313a6f0af603eccfd239bfa7549440510e0))

## 0.1.0 (2026-07-07)


### Features

* milpa/tool-runtime initial public release ([730f08f](https://github.com/getmilpa/tool-runtime/commit/730f08f1669a07890570dd3f849f6c807dbd42eb))


### Miscellaneous Chores

* release 0.1.0 ([93ae566](https://github.com/getmilpa/tool-runtime/commit/93ae5663a966090e600ed30b2dcbe39fc403bef4))
