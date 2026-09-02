# Changelog

## [0.39.0](https://github.com/getmilpa/agent/compare/v0.38.0...v0.39.0) (2026-09-02)


### Features

* an evidence receipt declares its scope and freshness is derived ([#81](https://github.com/getmilpa/agent/issues/81)) ([b4d3e8b](https://github.com/getmilpa/agent/commit/b4d3e8b2846ef140f0d0ec7513a68be0c8d10ea0))

## [0.38.0](https://github.com/getmilpa/agent/compare/v0.37.0...v0.38.0) (2026-09-02)


### Features

* evidence reads a predicate, not only a producer ([#79](https://github.com/getmilpa/agent/issues/79)) ([a3fa939](https://github.com/getmilpa/agent/commit/a3fa939916ed77f7272905f0aa5bc2ce25e8ef81))

## [0.37.0](https://github.com/getmilpa/agent/compare/v0.36.0...v0.37.0) (2026-09-02)


### Features

* ProgressReceipt — semantic progress per call, derived from the stream (greenhouse decisions/0185) ([#77](https://github.com/getmilpa/agent/issues/77)) ([d40439a](https://github.com/getmilpa/agent/commit/d40439a3f3f946dbfbe44f51d6fc5617c41fd320))

## [0.36.0](https://github.com/getmilpa/agent/compare/v0.35.0...v0.36.0) (2026-09-01)


### ⚠ BREAKING CHANGES

* the raw door to done closes — completeTodo is the only path (greenhouse decisions/0183) ([#75](https://github.com/getmilpa/agent/issues/75))

### Features

* the raw door to done closes — completeTodo is the only path (greenhouse decisions/0183) ([#75](https://github.com/getmilpa/agent/issues/75)) ([1614e2d](https://github.com/getmilpa/agent/commit/1614e2d813ac77b440739fa1e4922e79bff3eb1a))

## [0.35.0](https://github.com/getmilpa/agent/compare/v0.34.0...v0.35.0) (2026-09-01)


### Features

* whole-window budget for composition and compaction ([#73](https://github.com/getmilpa/agent/issues/73)) ([7f7635b](https://github.com/getmilpa/agent/commit/7f7635be8f6b35573c798a2c9a46cd5a0789cbf1))

## [0.34.0](https://github.com/getmilpa/agent/compare/v0.33.0...v0.34.0) (2026-09-01)


### Features

* evidence-backed work state and truncation honesty in session facts ([#71](https://github.com/getmilpa/agent/issues/71)) ([3cc0991](https://github.com/getmilpa/agent/commit/3cc099118c2b0ca88ae965d78ccc5c5c7125187e))

## [0.33.0](https://github.com/getmilpa/agent/compare/v0.32.0...v0.33.0) (2026-09-01)


### Features

* preserve operational facts across compaction ([#69](https://github.com/getmilpa/agent/issues/69)) ([56654d3](https://github.com/getmilpa/agent/commit/56654d3193f4b0e7523d7a57ff02e76d82de312a))

## [0.32.0](https://github.com/getmilpa/agent/compare/v0.31.0...v0.32.0) (2026-09-01)


### Features

* narrow session-fact queries for cheap recovery ([#67](https://github.com/getmilpa/agent/issues/67)) ([d1e4d58](https://github.com/getmilpa/agent/commit/d1e4d580f1f6c49c68adffb604fde5335cce77c8))
* per-session evidence ledger grounding a todo's done ([#66](https://github.com/getmilpa/agent/issues/66)) ([a548a78](https://github.com/getmilpa/agent/commit/a548a78f539e7be6614836c81c4cf8f5a7261440))

## [0.31.0](https://github.com/getmilpa/agent/compare/v0.30.0...v0.31.0) (2026-08-30)


### Features

* a declared confirmation is a session confirmation, not a per-call signature ([#64](https://github.com/getmilpa/agent/issues/64)) ([f716853](https://github.com/getmilpa/agent/commit/f7168533ad47c18cb18336952ff5acc91e93518b))

## [0.30.0](https://github.com/getmilpa/agent/compare/v0.29.1...v0.30.0) (2026-08-30)


### Features

* record what a model call reasoned as its own session event ([#62](https://github.com/getmilpa/agent/issues/62)) ([0a4634c](https://github.com/getmilpa/agent/commit/0a4634c2b3c44c25487d12e16bd229a233e8d0f3))

## [0.29.1](https://github.com/getmilpa/agent/compare/v0.29.0...v0.29.1) (2026-08-30)


### Bug Fixes

* window-aware compaction (compact by token budget, not only turns) ([#60](https://github.com/getmilpa/agent/issues/60)) ([bf017b5](https://github.com/getmilpa/agent/commit/bf017b5fe4a7df4b8218da77eeeee3745a0a7180))

## [0.29.0](https://github.com/getmilpa/agent/compare/v0.28.0...v0.29.0) (2026-08-30)


### Features

* the session gate governs egress, not mutation alone ([0f30861](https://github.com/getmilpa/agent/commit/0f30861293215216c29ebb08d29511378157eb5a))
* the session gate governs egress, not mutation alone ([2d08288](https://github.com/getmilpa/agent/commit/2d082881057d92ba069ff49ab995e070f733b04c))

## [0.28.0](https://github.com/getmilpa/agent/compare/v0.27.0...v0.28.0) (2026-08-28)


### Features

* record what a model call cost as its own session.model_returned event ([#55](https://github.com/getmilpa/agent/issues/55)) ([08d66d6](https://github.com/getmilpa/agent/commit/08d66d6441789d7b50907f2a6e0a5a1a67dda06d))

## [0.27.0](https://github.com/getmilpa/agent/compare/v0.26.1...v0.27.0) (2026-08-25)


### Features

* a paused governed sequence is a first-class session fact ([#53](https://github.com/getmilpa/agent/issues/53)) ([66fa84c](https://github.com/getmilpa/agent/commit/66fa84c2ea3bf8d4d9c2677bb379a912a8bf9427))

## [0.26.1](https://github.com/getmilpa/agent/compare/v0.26.0...v0.26.1) (2026-08-24)


### Bug Fixes

* **projector:** a work cycle is any turn, not only an assistant one — real agent runs record user turn, then work, then response ([#51](https://github.com/getmilpa/agent/issues/51)) ([ffffe85](https://github.com/getmilpa/agent/commit/ffffe85c5c4b4de19e04c0697c384d5d92a6d1dc))

## [0.26.0](https://github.com/getmilpa/agent/compare/v0.25.0...v0.26.0) (2026-08-24)


### Features

* **store:** SessionStore::stream exposes raw events for the board's per-turn fold; retire the per-event operation card ([#49](https://github.com/getmilpa/agent/issues/49)) ([9b20f98](https://github.com/getmilpa/agent/commit/9b20f98a300ba80e9ff3f82491f4165ee6d3bdbc))

## [0.25.0](https://github.com/getmilpa/agent/compare/v0.24.0...v0.25.0) (2026-08-24)


### Features

* **projector:** the board's unit of work is the assistant turn — boardCards folds the stream into one card per turn ([#47](https://github.com/getmilpa/agent/issues/47)) ([1a1a1d9](https://github.com/getmilpa/agent/commit/1a1a1d98f7b842fe454aab6f93ff97176a67ade3))

## [0.24.0](https://github.com/getmilpa/agent/compare/v0.23.0...v0.24.0) (2026-08-24)


### Features

* **projector:** the executed operation is itself a board card — the board stops costing the agent attention ([#45](https://github.com/getmilpa/agent/issues/45)) ([04b38a7](https://github.com/getmilpa/agent/commit/04b38a7f97e8409937e6e65f69369e9657243eba))

## [0.23.0](https://github.com/getmilpa/agent/compare/v0.22.0...v0.23.0) (2026-08-21)


### Features

* a trial runs without consent when its composed profile fits the trial ceiling AND is confined ([#43](https://github.com/getmilpa/agent/issues/43)) ([afcfaa1](https://github.com/getmilpa/agent/commit/afcfaa16e151045c098ef29c8c4f60328dffe7c3))

## [0.22.0](https://github.com/getmilpa/agent/compare/v0.21.0...v0.22.0) (2026-08-21)


### ⚠ BREAKING CHANGES

* milpa/agent now depends on milpa/command (>=0.17) so the comparison happens in one place; Session gains a named ctor parameter.

### Features

* a grant carries an envelope; allows() and decide() judge the composed call against it ([72cbc88](https://github.com/getmilpa/agent/commit/72cbc888d3e4259cc098c1afd69ae06771973af9))

## [0.21.0](https://github.com/getmilpa/agent/compare/v0.20.0...v0.21.0) (2026-08-19)


### Features

* SessionStore::loadAll() reconstructs every session in one log read ([cc29f28](https://github.com/getmilpa/agent/commit/cc29f28be1e2ca40c3bcb85f30731b0024a475d9))
* SessionStore::loadAll() reconstructs every session in one log read ([989edd1](https://github.com/getmilpa/agent/commit/989edd1493cedbb15394523aad6fcb9fb89cbba4))

## [0.20.0](https://github.com/getmilpa/agent/compare/v0.19.0...v0.20.0) (2026-08-19)


### Features

* a composition that lowers a ceiling is recorded as a fact ([#37](https://github.com/getmilpa/agent/issues/37)) ([4e5af0d](https://github.com/getmilpa/agent/commit/4e5af0d68d9a3eca009bf255286c108eb1ddee45))

## [0.19.0](https://github.com/getmilpa/agent/compare/v0.18.0...v0.19.0) (2026-08-18)


### Features

* a session stores the signed assertion that names its owner ([#35](https://github.com/getmilpa/agent/issues/35)) ([5239361](https://github.com/getmilpa/agent/commit/52393614629950d32780ea786a638c3a86e9a496))

## [0.18.0](https://github.com/getmilpa/agent/compare/v0.17.1...v0.18.0) (2026-08-18)


### Features

* declare each session window message class ([#33](https://github.com/getmilpa/agent/issues/33)) ([dfef1c8](https://github.com/getmilpa/agent/commit/dfef1c898ed7522841c43bc0da0e9c030b0f7154))

## [0.17.1](https://github.com/getmilpa/agent/compare/v0.17.0...v0.17.1) (2026-08-17)


### Bug Fixes

* wait for relevant release checks ([#31](https://github.com/getmilpa/agent/issues/31)) ([525f651](https://github.com/getmilpa/agent/commit/525f651b86d4d5c139f6b87aa2b3f84406286158))

## [0.17.0](https://github.com/getmilpa/agent/compare/v0.16.0...v0.17.0) (2026-08-17)


### Features

* the system prompt becomes a fact appended when it changes ([#28](https://github.com/getmilpa/agent/issues/28)) ([6361d87](https://github.com/getmilpa/agent/commit/6361d878acebe215ecd6f4a475643986496a7736))

## [0.16.0](https://github.com/getmilpa/agent/compare/v0.15.0...v0.16.0) (2026-08-17)


### Features

* an event that declares the effect happened, and keeps two identities apart ([#25](https://github.com/getmilpa/agent/issues/25)) ([daed434](https://github.com/getmilpa/agent/commit/daed4347af0c95b29d1caff61d0b756b77969807))

## [0.8.0](https://github.com/getmilpa/agent/compare/v0.7.0...v0.8.0) (2026-08-07)


### Features

* an empty --first lifts the standing obligation — and the lift ends the discipline ([a45c5cc](https://github.com/getmilpa/agent/commit/a45c5ccfd4a14b894aab17e0b1f1ccef6f2f0fcf))

## [0.7.0](https://github.com/getmilpa/agent/compare/v0.6.0...v0.7.0) (2026-08-06)


### Features

* cards carry lineage and answering projects — the board's source becomes fully legible ([6ff38bd](https://github.com/getmilpa/agent/commit/6ff38bdebb15e297da275385d71dd1230da37b24))

## [0.6.0](https://github.com/getmilpa/agent/compare/v0.5.2...v0.6.0) (2026-08-05)


### Features

* **channel:** un mensaje entre sesiones del mismo árbol, como evento del stream ([44275db](https://github.com/getmilpa/agent/commit/44275db6e2feb469ac2ac6acb9c3789960df4eb7))

## [0.5.2](https://github.com/getmilpa/agent/compare/v0.5.1...v0.5.2) (2026-08-04)


### Bug Fixes

* **capability:** declara el contrato de agent.sessions ([b21efe5](https://github.com/getmilpa/agent/commit/b21efe56560b4708c6fb9fca8352b7af227d5280))

## [0.5.1](https://github.com/getmilpa/agent/compare/v0.5.0...v0.5.1) (2026-08-04)


### Bug Fixes

* **composer:** declarar type milpa-capability para que el paquete sea descubrible por lo que es ([d9943cc](https://github.com/getmilpa/agent/commit/d9943cccbfc9eed323a2baa501a8b896df8ec1a3))

## [0.5.0](https://github.com/getmilpa/agent/compare/v0.4.0...v0.5.0) (2026-08-03)


### Features

* la proyección de una herramienta llamada lleva su resultado ([f9d04e7](https://github.com/getmilpa/agent/commit/f9d04e70e72c7b0878cf0183c5dd173d89bf1789))

## [0.4.0](https://github.com/getmilpa/agent/compare/v0.3.0...v0.4.0) (2026-08-02)


### Features

* questions carry a reason code, and decisions inherit reason and why ([0c3c7b5](https://github.com/getmilpa/agent/commit/0c3c7b51eb6cd7916642469ef6a81b68b2873d47))

## [0.3.0](https://github.com/getmilpa/agent/compare/v0.2.2...v0.3.0) (2026-08-02)


### Features

* activity is a projection, not a gap ([27f64a8](https://github.com/getmilpa/agent/commit/27f64a8ab932e40ae41eaa577416e9275d4c4e6e))

## [0.2.2](https://github.com/getmilpa/agent/compare/v0.2.1...v0.2.2) (2026-08-01)


### Bug Fixes

* the capability contract speaks English ([02acad5](https://github.com/getmilpa/agent/commit/02acad55723aa63a7343cc09e74be3aacce39134))

## [0.2.1](https://github.com/getmilpa/agent/compare/v0.2.0...v0.2.1) (2026-08-01)


### Bug Fixes

* este paquete declara que aporta ([21e97a3](https://github.com/getmilpa/agent/commit/21e97a36f0a39ffc2c543a8bf5c0d79697c5e6db))

## [0.2.0](https://github.com/getmilpa/agent/compare/v0.1.0...v0.2.0) (2026-08-01)


### Features

* la sesion registra linaje, origen y que paso con lo que quedo abierto ([0e581cc](https://github.com/getmilpa/agent/commit/0e581cca770b95442b1376c50ec453cafa1a2d6f))

## 0.1.0 (2026-08-01)


### Features

* milpa/agent — sesiones largas como stream de eventos ([a8cfb43](https://github.com/getmilpa/agent/commit/a8cfb4352f26cb570513c52cea4ab2416c4d4ee8))


### Bug Fixes

* el README lleva el lockup de la familia y la atribucion al pie ([29b5f58](https://github.com/getmilpa/agent/commit/29b5f58f126d57de9e729b8641e1570d3e724792))


### Miscellaneous Chores

* primer release como v0.1.0 ([3e9c256](https://github.com/getmilpa/agent/commit/3e9c2566f090b9b7b66baa65ab413cddf663d306))
