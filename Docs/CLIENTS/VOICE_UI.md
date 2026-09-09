> **CURRENT Web Voice UI.**

# Voice UI

**Status.** Voice Runtime pipeline MANUAL PASS for **Рация**. **Диалог Beta** is IMPLEMENTED / NOT VALIDATED. Hands-free capture is not our VAD; Beta uses ElevenLabs turn detection. Legacy PTT is kept.

Voice **Рация** is push-to-talk turn-based conversation. Hold the large radio button to record and release it to send. **Диалог Beta** is continuous conversation without that button. The separate mic button remains mute/unmute in both modes. After TTS in Рация, the session is ready for the next held turn.

Voice **UI** ≠ Voice **Runtime**.

| Layer | Owns |
| --- | --- |
| Voice Runtime | sessions, STT, TTS, events — [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) |
| Voice UI | Orb, transcript, controls, `VoiceVisualizationState` |

A speech vendor can change without rewriting the Orb. The Orb never calls `VoiceRuntimeService`.

---

## Place

Voice Mode is a mode of the selected conversation on Web Workspace. Not a separate User Space. Not a human avatar. Desktop is cancelled. Mobile is a deferred companion.

`/jarvis/chats/{id}` or `/chat/chats/{id}` Text/Voice toggle. Voice uses **that** `conversation_id`. Switching Voice → Text ends the voice session and keeps the same thread.

---

## M24 Orb

Module (Laravel/Inertia-free visualization engine):

```
resources/js/voice/
  visualization/   VoiceVisualizationState, presets, GLSL, OrbEngine, JarvisVoiceOrb, CSS fallback
  audio/           VoiceAudioAnalyzer, synthetic speaking demo
  components/      VoiceDemoDrawer
```

Component: `JarvisVoiceOrb` receives only `VoiceVisualizationState` (or a ref the engine polls). No provider names, no ElevenLabs, no Conversation AI details.

### VoiceVisualizationState

```
state
inputAmplitude          // 0..1, smoothed
outputAmplitude         // 0..1, smoothed
frequencyBands          // sub, low, lowMid, mid, highMid, high (0..1)
connectionState
transitionProgress
isMuted
reducedMotion
```

Production `state` maps from `voice_sessions.status`. Amplitudes come from the local Web Audio analyser, not the backend.

### Visual states

| State | Motion |
| --- | --- |
| idle | slow breath, low glow, subtle deformation |
| connecting | tighter sphere, aligning/rotating lines |
| listening | input amplitude + bands deform surface and filaments |
| transcribing | contraction, directional inner rotation (not thinking) |
| thinking | procedural filaments/particles; **no** fake waveform |
| speaking | output amplitude (or demo synthetic energy); stronger than idle |
| interrupted | fast smooth collapse toward listening-ready |
| muted | alive but dampened glow/deform/particles |
| error | unstable/frozen pulse, restrained warning accent; text shows the error |
| ended | energy fades; does not vanish instantly |

Interpolation: visual presets ease toward the target. Interrupt/listening are faster; idle/thinking/ended are slower.

### Layers

1. Translucent icosphere (custom vertex/fragment GLSL: noise displacement, fresnel)
2. Inner energy shell + backface glass
3. Thin flowing energy lines (not Saturn rings)
4. Subtle orbiting particles
5. Shader rim halo — no UnrealBloom (bloom filled an opaque square and washed the sphere)

Identity: cyan/steel precision core. Not a Siri rainbow clone. No OrbitControls. Subtle camera drift only.

### Audio (local, no providers)

`VoiceAudioAnalyzer`: `connectInputStream`, `connectOutputAudio`, smoothed RMS and frequency bands. Shared `AudioContext` is resumed on the Text→Voice gesture. Microphone stream lives for the session except mute/end/text/unmount. Analyser does **not** archive audio.

Normal recording does not auto-start and silence does not finalize a turn. Pointer down starts `MediaRecorder`; pointer up/cancel/lost capture finalizes it. A hard maximum utterance still bounds recording. Pressing push-to-talk while `speaking`/`thinking` invokes Interrupt before capture.

Listening can visualize the mic even when STT is not configured. Speaking visualization uses playback analyser when TTS audio exists; otherwise demo synthetic output energy (marked as demo, not fake TTS).

### Demo mode

Enable with `?voice_demo=1` or `VITE_VOICE_DEMO_MODE=true`. Hidden drawer cycles all states. No speech providers required. Not shown in the normal Voice chrome.

### Fallback / performance

- No WebGL → CSS orb (`CssFallbackOrb`)
- `prefers-reduced-motion` reduces deform, particles, camera, pulses; text state remains
- DPR clamped (`min(devicePixelRatio, 2)`, lower on weak/mobile)
- Quality tiers; repeated slow frames drop halo/particles/DPR
- ResizeObserver; dispose geometries, materials, renderer, rAF, analyser
- WebGL intensity is raised about 12% on desktop and 50% below 768px; the CSS fallback uses matching responsive brightness/saturation

---

## Workspace client

Mode selector (local preference, default **Рация**):

1. **Рация** — `VoiceSession`. Controls: **hold-to-talk**, Mute/Unmute, Interrupt (when speaking/thinking), device settings, End, Text. Labels include «Рация — зажмите кнопку», «Говорите…», Thinking… / Speaking… / Muted.
2. **Диалог Beta** — `RealtimeVoiceSession`. Controls: Mute, End Voice, switch to Рация, Text. No PTT. Orb maps ElevenLabs SDK states onto the existing `JarvisVoiceOrb` (`connecting`, `listening`, `user_speaking`, `thinking`, `speaking`, `interrupted`, `muted`, `error`, `ended`). If Beta cannot start, show a clear error and «Переключиться на Рацию».

`WorkspaceVoice` owns the selector. Switching conversation ends the current realtime session and starts a new one bound to the new chat if Beta stays on. End Voice does not delete the Jarvis conversation.

If STT/TTS are not configured: Orb keeps working; status **Speech providers not configured.** — not a crash (Рация).

If Voice opens without a usable user gesture, a single **Enable microphone** CTA appears (Рация). After Text→Voice it should not. **Enable audio** appears only if TTS autoplay is blocked.

---

## Mobile (deferred)

If a Flutter companion is built later, keep the same `VoiceVisualizationState` semantics. Do not reuse Three.js in Flutter. Desktop is cancelled.

---

## Out of scope

- Telephony UI
- Binding visualization to a vendor SDK
- Desktop / Tauri shell
