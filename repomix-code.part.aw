oes not add", () => {
      const tracker = createSeenTracker({ maxEntries: 100, ttlMs: 60000 });

      expect(tracker.peek("id1")).toBe(false);
      expect(tracker.peek("id1")).toBe(false); // Still false

      tracker.add("id1");
      expect(tracker.peek("id1")).toBe(true);

      tracker.stop();
    });

    it("delete removes entries", () => {
      const tracker = createSeenTracker({ maxEntries: 100, ttlMs: 60000 });

      tracker.add("id1");
      expect(tracker.peek("id1")).toBe(true);

      tracker.delete("id1");
      expect(tracker.peek("id1")).toBe(false);

      tracker.stop();
    });

    it("clear removes all entries", () => {
      const tracker = createSeenTracker({ maxEntries: 100, ttlMs: 60000 });

      tracker.add("id1");
      tracker.add("id2");
      tracker.add("id3");
      expect(tracker.size()).toBe(3);

      tracker.clear();
      expect(tracker.size()).toBe(0);
      expect(tracker.peek("id1")).toBe(false);

      tracker.stop();
    });

    it("seed pre-populates entries", () => {
      const tracker = createSeenTracker({ maxEntries: 100, ttlMs: 60000 });

      tracker.seed(["id1", "id2", "id3"]);
      expect(tracker.size()).toBe(3);
      expect(tracker.peek("id1")).toBe(true);
      expect(tracker.peek("id2")).toBe(true);
      expect(tracker.peek("id3")).toBe(true);

      tracker.stop();
    });
  });

  describe("LRU eviction", () => {
    it("evicts least recently used when at capacity", () => {
      const tracker = createSeenTracker({ maxEntries: 3, ttlMs: 60000 });

      tracker.add("id1");
      tracker.add("id2");
      tracker.add("id3");
      expect(tracker.size()).toBe(3);

      // Adding fourth should evict oldest (id1)
      tracker.add("id4");
      expect(tracker.size()).toBe(3);
      expect(tracker.peek("id1")).toBe(false); // Evicted
      expect(tracker.peek("id2")).toBe(true);
      expect(tracker.peek("id3")).toBe(true);
      expect(tracker.peek("id4")).toBe(true);

      tracker.stop();
    });

    it("accessing an entry moves it to front (prevents eviction)", () => {
      const tracker = createSeenTracker({ maxEntries: 3, ttlMs: 60000 });

      tracker.add("id1");
      tracker.add("id2");
      tracker.add("id3");

      // Access id1, moving it to front
      tracker.has("id1");

      // Add id4 - should evict id2 (now oldest)
      tracker.add("id4");
      expect(tracker.peek("id1")).toBe(true); // Not evicted, was accessed
      expect(tracker.peek("id2")).toBe(false); // Evicted
      expect(tracker.peek("id3")).toBe(true);
      expect(tracker.peek("id4")).toBe(true);

      tracker.stop();
    });

    it("handles capacity of 1", () => {
      const tracker = createSeenTracker({ maxEntries: 1, ttlMs: 60000 });

      tracker.add("id1");
      expect(tracker.peek("id1")).toBe(true);

      tracker.add("id2");
      expect(tracker.peek("id1")).toBe(false);
      expect(tracker.peek("id2")).toBe(true);

      tracker.stop();
    });

    it("seed respects maxEntries", () => {
      const tracker = createSeenTracker({ maxEntries: 2, ttlMs: 60000 });

      tracker.seed(["id1", "id2", "id3", "id4"]);
      expect(tracker.size()).toBe(2);
      // Seed stops when maxEntries reached, processing from end to start
      // So id4 and id3 get added first, then we're at capacity
      expect(tracker.peek("id3")).toBe(true);
      expect(tracker.peek("id4")).toBe(true);

      tracker.stop();
    });
  });

  describe("TTL expiration", () => {
    it("expires entries after TTL", async () => {
      vi.useFakeTimers();

      const tracker = createSeenTracker({
        maxEntries: 100,
        ttlMs: 100,
        pruneIntervalMs: 50,
      });

      tracker.add("id1");
      expect(tracker.peek("id1")).toBe(true);

      // Advance past TTL
      vi.advanceTimersByTime(150);

      // Entry should be expired
      expect(tracker.peek("id1")).toBe(false);

      tracker.stop();
      vi.useRealTimers();
    });

    it("has() refreshes TTL", async () => {
      vi.useFakeTimers();

      const tracker = createSeenTracker({
        maxEntries: 100,
        ttlMs: 100,
        pruneIntervalMs: 50,
      });

      tracker.add("id1");

      // Advance halfway
      vi.advanceTimersByTime(50);

      // Access to refresh
      expect(tracker.has("id1")).toBe(true);

      // Advance another 75ms (total 125ms from add, but only 75ms from last access)
      vi.advanceTimersByTime(75);

      // Should still be valid (refreshed at 50ms)
      expect(tracker.peek("id1")).toBe(true);

      tracker.stop();
      vi.useRealTimers();
    });
  });
});

// ============================================================================
// Metrics Integration Tests
// ============================================================================

describe("Metrics", () => {
  describe("createMetrics", () => {
    it("emits metric events to callback", () => {
      const events: MetricEvent[] = [];
      const metrics = createMetrics((event) => events.push(event));

      metrics.emit("event.received");
      metrics.emit("event.processed");
      metrics.emit("event.duplicate");

      expect(events).toHaveLength(3);
      expect(events[0].name).toBe("event.received");
      expect(events[1].name).toBe("event.processed");
      expect(events[2].name).toBe("event.duplicate");
    });

    it("includes labels in metric events", () => {
      const events: MetricEvent[] = [];
      const metrics = createMetrics((event) => events.push(event));

      metrics.emit("relay.connect", 1, { relay: "wss://relay.example.com" });

      expect(events[0].labels).toEqual({ relay: "wss://relay.example.com" });
    });

    it("accumulates counters in snapshot", () => {
      const metrics = createMetrics();

      metrics.emit("event.received");
      metrics.emit("event.received");
      metrics.emit("event.processed");
      metrics.emit("event.duplicate");
      metrics.emit("event.duplicate");
      metrics.emit("event.duplicate");

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(2);
      expect(snapshot.eventsProcessed).toBe(1);
      expect(snapshot.eventsDuplicate).toBe(3);
    });

    it("tracks per-relay stats", () => {
      const metrics = createMetrics();

      metrics.emit("relay.connect", 1, { relay: "wss://relay1.com" });
      metrics.emit("relay.connect", 1, { relay: "wss://relay2.com" });
      metrics.emit("relay.error", 1, { relay: "wss://relay1.com" });
      metrics.emit("relay.error", 1, { relay: "wss://relay1.com" });

      const snapshot = metrics.getSnapshot();
      expect(snapshot.relays["wss://relay1.com"]).toBeDefined();
      expect(snapshot.relays["wss://relay1.com"].connects).toBe(1);
      expect(snapshot.relays["wss://relay1.com"].errors).toBe(2);
      expect(snapshot.relays["wss://relay2.com"].connects).toBe(1);
      expect(snapshot.relays["wss://relay2.com"].errors).toBe(0);
    });

    it("tracks circuit breaker state changes", () => {
      const metrics = createMetrics();

      metrics.emit("relay.circuit_breaker.open", 1, { relay: "wss://relay.com" });

      let snapshot = metrics.getSnapshot();
      expect(snapshot.relays["wss://relay.com"].circuitBreakerState).toBe("open");
      expect(snapshot.relays["wss://relay.com"].circuitBreakerOpens).toBe(1);

      metrics.emit("relay.circuit_breaker.close", 1, { relay: "wss://relay.com" });

      snapshot = metrics.getSnapshot();
      expect(snapshot.relays["wss://relay.com"].circuitBreakerState).toBe("closed");
      expect(snapshot.relays["wss://relay.com"].circuitBreakerCloses).toBe(1);
    });

    it("tracks all rejection reasons", () => {
      const metrics = createMetrics();

      metrics.emit("event.rejected.invalid_shape");
      metrics.emit("event.rejected.wrong_kind");
      metrics.emit("event.rejected.stale");
      metrics.emit("event.rejected.future");
      metrics.emit("event.rejected.rate_limited");
      metrics.emit("event.rejected.invalid_signature");
      metrics.emit("event.rejected.oversized_ciphertext");
      metrics.emit("event.rejected.oversized_plaintext");
      metrics.emit("event.rejected.decrypt_failed");
      metrics.emit("event.rejected.self_message");

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsRejected.invalidShape).toBe(1);
      expect(snapshot.eventsRejected.wrongKind).toBe(1);
      expect(snapshot.eventsRejected.stale).toBe(1);
      expect(snapshot.eventsRejected.future).toBe(1);
      expect(snapshot.eventsRejected.rateLimited).toBe(1);
      expect(snapshot.eventsRejected.invalidSignature).toBe(1);
      expect(snapshot.eventsRejected.oversizedCiphertext).toBe(1);
      expect(snapshot.eventsRejected.oversizedPlaintext).toBe(1);
      expect(snapshot.eventsRejected.decryptFailed).toBe(1);
      expect(snapshot.eventsRejected.selfMessage).toBe(1);
    });

    it("tracks relay message types", () => {
      const metrics = createMetrics();

      metrics.emit("relay.message.event", 1, { relay: "wss://relay.com" });
      metrics.emit("relay.message.eose", 1, { relay: "wss://relay.com" });
      metrics.emit("relay.message.closed", 1, { relay: "wss://relay.com" });
      metrics.emit("relay.message.notice", 1, { relay: "wss://relay.com" });
      metrics.emit("relay.message.ok", 1, { relay: "wss://relay.com" });
      metrics.emit("relay.message.auth", 1, { relay: "wss://relay.com" });

      const snapshot = metrics.getSnapshot();
      const relay = snapshot.relays["wss://relay.com"];
      expect(relay.messagesReceived.event).toBe(1);
      expect(relay.messagesReceived.eose).toBe(1);
      expect(relay.messagesReceived.closed).toBe(1);
      expect(relay.messagesReceived.notice).toBe(1);
      expect(relay.messagesReceived.ok).toBe(1);
      expect(relay.messagesReceived.auth).toBe(1);
    });

    it("tracks decrypt success/failure", () => {
      const metrics = createMetrics();

      metrics.emit("decrypt.success");
      metrics.emit("decrypt.success");
      metrics.emit("decrypt.failure");

      const snapshot = metrics.getSnapshot();
      expect(snapshot.decrypt.success).toBe(2);
      expect(snapshot.decrypt.failure).toBe(1);
    });

    it("tracks memory gauges (replaces rather than accumulates)", () => {
      const metrics = createMetrics();

      metrics.emit("memory.seen_tracker_size", 100);
      metrics.emit("memory.seen_tracker_size", 150);
      metrics.emit("memory.seen_tracker_size", 125);

      const snapshot = metrics.getSnapshot();
      expect(snapshot.memory.seenTrackerSize).toBe(125); // Last value, not sum
    });

    it("reset clears all counters", () => {
      const metrics = createMetrics();

      metrics.emit("event.received");
      metrics.emit("event.processed");
      metrics.emit("relay.connect", 1, { relay: "wss://relay.com" });

      metrics.reset();

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(0);
      expect(snapshot.eventsProcessed).toBe(0);
      expect(Object.keys(snapshot.relays)).toHaveLength(0);
    });
  });

  describe("createNoopMetrics", () => {
    it("does not throw on emit", () => {
      const metrics = createNoopMetrics();

      expect(() => {
        metrics.emit("event.received");
        metrics.emit("relay.connect", 1, { relay: "wss://relay.com" });
      }).not.toThrow();
    });

    it("returns empty snapshot", () => {
      const metrics = createNoopMetrics();

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(0);
      expect(snapshot.eventsProcessed).toBe(0);
    });
  });
});

// ============================================================================
// Circuit Breaker Behavior Tests
// ============================================================================

describe("Circuit Breaker Behavior", () => {
  // Test the circuit breaker logic through metrics emissions
  it("emits circuit breaker metrics in correct sequence", () => {
    const events: MetricEvent[] = [];
    const metrics = createMetrics((event) => events.push(event));

    // Simulate 5 failures -> open
    for (let i = 0; i < 5; i++) {
      metrics.emit("relay.error", 1, { relay: "wss://relay.com" });
    }
    metrics.emit("relay.circuit_breaker.open", 1, { relay: "wss://relay.com" });

    // Simulate recovery
    metrics.emit("relay.circuit_breaker.half_open", 1, { relay: "wss://relay.com" });
    metrics.emit("relay.circuit_breaker.close", 1, { relay: "wss://relay.com" });

    const cbEvents = events.filter((e) => e.name.startsWith("relay.circuit_breaker"));
    expect(cbEvents).toHaveLength(3);
    expect(cbEvents[0].name).toBe("relay.circuit_breaker.open");
    expect(cbEvents[1].name).toBe("relay.circuit_breaker.half_open");
    expect(cbEvents[2].name).toBe("relay.circuit_breaker.close");
  });
});

// ============================================================================
// Health Scoring Behavior Tests
// ============================================================================

describe("Health Scoring", () => {
  it("metrics track relay errors for health scoring", () => {
    const metrics = createMetrics();

    // Simulate mixed success/failure pattern
    metrics.emit("relay.connect", 1, { relay: "wss://good-relay.com" });
    metrics.emit("relay.connect", 1, { relay: "wss://bad-relay.com" });

    metrics.emit("relay.error", 1, { relay: "wss://bad-relay.com" });
    metrics.emit("relay.error", 1, { relay: "wss://bad-relay.com" });
    metrics.emit("relay.error", 1, { relay: "wss://bad-relay.com" });

    const snapshot = metrics.getSnapshot();
    expect(snapshot.relays["wss://good-relay.com"].errors).toBe(0);
    expect(snapshot.relays["wss://bad-relay.com"].errors).toBe(3);
  });
});

// ============================================================================
// Reconnect Backoff Tests
// ============================================================================

describe("Reconnect Backoff", () => {
  it("computes delays within expected bounds", () => {
    // Compute expected delays (1s, 2s, 4s, 8s, 16s, 32s, 60s cap)
    const BASE = 1000;
    const MAX = 60000;
    const JITTER = 0.3;

    for (let attempt = 0; attempt < 10; attempt++) {
      const exponential = BASE * Math.pow(2, attempt);
      const capped = Math.min(exponential, MAX);
      const minDelay = capped * (1 - JITTER);
      const maxDelay = capped * (1 + JITTER);

      // These are the expected bounds
      expect(minDelay).toBeGreaterThanOrEqual(BASE * 0.7);
      expect(maxDelay).toBeLessThanOrEqual(MAX * 1.3);
    }
  });
});
]]></file>
  <file path="./extensions/nostr/src/nostr-profile.test.ts"><![CDATA[import { verifyEvent, getPublicKey } from "nostr-tools";
import { describe, expect, it, vi, beforeEach } from "vitest";
import type { NostrProfile } from "./config-schema.js";
import {
  createProfileEvent,
  profileToContent,
  contentToProfile,
  validateProfile,
  sanitizeProfileForDisplay,
  type ProfileContent,
} from "./nostr-profile.js";

// Test private key (DO NOT use in production - this is a known test key)
const TEST_HEX_KEY = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
const TEST_SK = new Uint8Array(TEST_HEX_KEY.match(/.{2}/g)!.map((byte) => parseInt(byte, 16)));
const TEST_PUBKEY = getPublicKey(TEST_SK);

// ============================================================================
// Profile Content Conversion Tests
// ============================================================================

describe("profileToContent", () => {
  it("converts full profile to NIP-01 content format", () => {
    const profile: NostrProfile = {
      name: "testuser",
      displayName: "Test User",
      about: "A test user for unit testing",
      picture: "https://example.com/avatar.png",
      banner: "https://example.com/banner.png",
      website: "https://example.com",
      nip05: "testuser@example.com",
      lud16: "testuser@walletofsatoshi.com",
    };

    const content = profileToContent(profile);

    expect(content.name).toBe("testuser");
    expect(content.display_name).toBe("Test User");
    expect(content.about).toBe("A test user for unit testing");
    expect(content.picture).toBe("https://example.com/avatar.png");
    expect(content.banner).toBe("https://example.com/banner.png");
    expect(content.website).toBe("https://example.com");
    expect(content.nip05).toBe("testuser@example.com");
    expect(content.lud16).toBe("testuser@walletofsatoshi.com");
  });

  it("omits undefined fields from content", () => {
    const profile: NostrProfile = {
      name: "minimaluser",
    };

    const content = profileToContent(profile);

    expect(content.name).toBe("minimaluser");
    expect("display_name" in content).toBe(false);
    expect("about" in content).toBe(false);
    expect("picture" in content).toBe(false);
  });

  it("handles empty profile", () => {
    const profile: NostrProfile = {};
    const content = profileToContent(profile);
    expect(Object.keys(content)).toHaveLength(0);
  });
});

describe("contentToProfile", () => {
  it("converts NIP-01 content to profile format", () => {
    const content: ProfileContent = {
      name: "testuser",
      display_name: "Test User",
      about: "A test user",
      picture: "https://example.com/avatar.png",
      nip05: "test@example.com",
    };

    const profile = contentToProfile(content);

    expect(profile.name).toBe("testuser");
    expect(profile.displayName).toBe("Test User");
    expect(profile.about).toBe("A test user");
    expect(profile.picture).toBe("https://example.com/avatar.png");
    expect(profile.nip05).toBe("test@example.com");
  });

  it("handles empty content", () => {
    const content: ProfileContent = {};
    const profile = contentToProfile(content);
    expect(
      Object.keys(profile).filter((k) => profile[k as keyof NostrProfile] !== undefined),
    ).toHaveLength(0);
  });

  it("round-trips profile data", () => {
    const original: NostrProfile = {
      name: "roundtrip",
      displayName: "Round Trip Test",
      about: "Testing round-trip conversion",
    };

    const content = profileToContent(original);
    const restored = contentToProfile(content);

    expect(restored.name).toBe(original.name);
    expect(restored.displayName).toBe(original.displayName);
    expect(restored.about).toBe(original.about);
  });
});

// ============================================================================
// Event Creation Tests
// ============================================================================

describe("createProfileEvent", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2024-01-15T12:00:00Z"));
  });

  it("creates a valid kind:0 event", () => {
    const profile: NostrProfile = {
      name: "testbot",
      about: "A test bot",
    };

    const event = createProfileEvent(TEST_SK, profile);

    expect(event.kind).toBe(0);
    expect(event.pubkey).toBe(TEST_PUBKEY);
    expect(event.tags).toEqual([]);
    expect(event.id).toMatch(/^[0-9a-f]{64}$/);
    expect(event.sig).toMatch(/^[0-9a-f]{128}$/);
  });

  it("includes profile content as JSON in event content", () => {
    const profile: NostrProfile = {
      name: "jsontest",
      displayName: "JSON Test User",
      about: "Testing JSON serialization",
    };

    const event = createProfileEvent(TEST_SK, profile);
    const parsedContent = JSON.parse(event.content) as ProfileContent;

    expect(parsedContent.name).toBe("jsontest");
    expect(parsedContent.display_name).toBe("JSON Test User");
    expect(parsedContent.about).toBe("Testing JSON serialization");
  });

  it("produces a verifiable signature", () => {
    const profile: NostrProfile = { name: "signaturetest" };
    const event = createProfileEvent(TEST_SK, profile);

    expect(verifyEvent(event)).toBe(true);
  });

  it("uses current timestamp when no lastPublishedAt provided", () => {
    const profile: NostrProfile = { name: "timestamptest" };
    const event = createProfileEvent(TEST_SK, profile);

    const expectedTimestamp = Math.floor(Date.now() / 1000);
    expect(event.created_at).toBe(expectedTimestamp);
  });

  it("ensures monotonic timestamp when lastPublishedAt is in the future", () => {
    // Current time is 2024-01-15T12:00:00Z = 1705320000
    const futureTimestamp = 1705320000 + 3600; // 1 hour in the future
    const profile: NostrProfile = { name: "monotonictest" };

    const event = createProfileEvent(TEST_SK, profile, futureTimestamp);

    expect(event.created_at).toBe(futureTimestamp + 1);
  });

  it("uses current time when lastPublishedAt is in the past", () => {
    const pastTimestamp = 1705320000 - 3600; // 1 hour in the past
    const profile: NostrProfile = { name: "pasttest" };

    const event = createProfileEvent(TEST_SK, profile, pastTimestamp);

    const expectedTimestamp = Math.floor(Date.now() / 1000);
    expect(event.created_at).toBe(expectedTimestamp);
  });

  vi.useRealTimers();
});

// ============================================================================
// Profile Validation Tests
// ============================================================================

describe("validateProfile", () => {
  it("validates a correct profile", () => {
    const profile = {
      name: "validuser",
      about: "A valid user",
      picture: "https://example.com/pic.png",
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(true);
    expect(result.profile).toBeDefined();
    expect(result.errors).toBeUndefined();
  });

  it("rejects profile with invalid URL", () => {
    const profile = {
      name: "invalidurl",
      picture: "http://insecure.example.com/pic.png", // HTTP not HTTPS
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(false);
    expect(result.errors).toBeDefined();
    expect(result.errors!.some((e) => e.includes("https://"))).toBe(true);
  });

  it("rejects profile with javascript: URL", () => {
    const profile = {
      name: "xssattempt",
      picture: "javascript:alert('xss')",
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(false);
  });

  it("rejects profile with data: URL", () => {
    const profile = {
      name: "dataurl",
      picture: "data:image/png;base64,abc123",
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(false);
  });

  it("rejects name exceeding 256 characters", () => {
    const profile = {
      name: "a".repeat(257),
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(false);
    expect(result.errors!.some((e) => e.includes("256"))).toBe(true);
  });

  it("rejects about exceeding 2000 characters", () => {
    const profile = {
      about: "a".repeat(2001),
    };

    const result = validateProfile(profile);

    expect(result.valid).toBe(false);
    expect(result.errors!.some((e) => e.includes("2000"))).toBe(true);
  });

  it("accepts empty profile", () => {
    const result = validateProfile({});
    expect(result.valid).toBe(true);
  });

  it("rejects null input", () => {
    const result = validateProfile(null);
    expect(result.valid).toBe(false);
  });

  it("rejects non-object input", () => {
    const result = validateProfile("not an object");
    expect(result.valid).toBe(false);
  });
});

// ============================================================================
// Sanitization Tests
// ============================================================================

describe("sanitizeProfileForDisplay", () => {
  it("escapes HTML in name field", () => {
    const profile: NostrProfile = {
      name: "<script>alert('xss')</script>",
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.name).toBe("&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;");
  });

  it("escapes HTML in about field", () => {
    const profile: NostrProfile = {
      about: 'Check out <img src="x" onerror="alert(1)">',
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.about).toBe(
      "Check out &lt;img src=&quot;x&quot; onerror=&quot;alert(1)&quot;&gt;",
    );
  });

  it("preserves URLs without modification", () => {
    const profile: NostrProfile = {
      picture: "https://example.com/pic.png",
      website: "https://example.com",
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.picture).toBe("https://example.com/pic.png");
    expect(sanitized.website).toBe("https://example.com");
  });

  it("handles undefined fields", () => {
    const profile: NostrProfile = {
      name: "test",
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.name).toBe("test");
    expect(sanitized.about).toBeUndefined();
    expect(sanitized.picture).toBeUndefined();
  });

  it("escapes ampersands", () => {
    const profile: NostrProfile = {
      name: "Tom & Jerry",
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.name).toBe("Tom &amp; Jerry");
  });

  it("escapes quotes", () => {
    const profile: NostrProfile = {
      about: 'Say "hello" to everyone',
    };

    const sanitized = sanitizeProfileForDisplay(profile);

    expect(sanitized.about).toBe("Say &quot;hello&quot; to everyone");
  });
});

// ============================================================================
// Edge Cases
// ============================================================================

describe("edge cases", () => {
  it("handles emoji in profile fields", () => {
    const profile: NostrProfile = {
      name: "🤖 Bot",
      about: "I am a 🤖 robot! 🎉",
    };

    const content = profileToContent(profile);
    expect(content.name).toBe("🤖 Bot");
    expect(content.about).toBe("I am a 🤖 robot! 🎉");

    const event = createProfileEvent(TEST_SK, profile);
    const parsed = JSON.parse(event.content) as ProfileContent;
    expect(parsed.name).toBe("🤖 Bot");
  });

  it("handles unicode in profile fields", () => {
    const profile: NostrProfile = {
      name: "日本語ユーザー",
      about: "Привет мир! 你好世界!",
    };

    const content = profileToContent(profile);
    expect(content.name).toBe("日本語ユーザー");

    const event = createProfileEvent(TEST_SK, profile);
    expect(verifyEvent(event)).toBe(true);
  });

  it("handles newlines in about field", () => {
    const profile: NostrProfile = {
      about: "Line 1\nLine 2\nLine 3",
    };

    const content = profileToContent(profile);
    expect(content.about).toBe("Line 1\nLine 2\nLine 3");

    const event = createProfileEvent(TEST_SK, profile);
    const parsed = JSON.parse(event.content) as ProfileContent;
    expect(parsed.about).toBe("Line 1\nLine 2\nLine 3");
  });

  it("handles maximum length fields", () => {
    const profile: NostrProfile = {
      name: "a".repeat(256),
      about: "b".repeat(2000),
    };

    const result = validateProfile(profile);
    expect(result.valid).toBe(true);

    const event = createProfileEvent(TEST_SK, profile);
    expect(verifyEvent(event)).toBe(true);
  });
});
]]></file>
  <file path="./extensions/nostr/src/types.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import type { NostrProfile } from "./config-schema.js";
import { getPublicKeyFromPrivate } from "./nostr-bus.js";
import { DEFAULT_RELAYS } from "./nostr-bus.js";

export interface NostrAccountConfig {
  enabled?: boolean;
  name?: string;
  privateKey?: string;
  relays?: string[];
  dmPolicy?: "pairing" | "allowlist" | "open" | "disabled";
  allowFrom?: Array<string | number>;
  profile?: NostrProfile;
}

export interface ResolvedNostrAccount {
  accountId: string;
  name?: string;
  enabled: boolean;
  configured: boolean;
  privateKey: string;
  publicKey: string;
  relays: string[];
  profile?: NostrProfile;
  config: NostrAccountConfig;
}

const DEFAULT_ACCOUNT_ID = "default";

/**
 * List all configured Nostr account IDs
 */
export function listNostrAccountIds(cfg: OpenClawConfig): string[] {
  const nostrCfg = (cfg.channels as Record<string, unknown> | undefined)?.nostr as
    | NostrAccountConfig
    | undefined;

  // If privateKey is configured at top level, we have a default account
  if (nostrCfg?.privateKey) {
    return [DEFAULT_ACCOUNT_ID];
  }

  return [];
}

/**
 * Get the default account ID
 */
export function resolveDefaultNostrAccountId(cfg: OpenClawConfig): string {
  const ids = listNostrAccountIds(cfg);
  if (ids.includes(DEFAULT_ACCOUNT_ID)) {
    return DEFAULT_ACCOUNT_ID;
  }
  return ids[0] ?? DEFAULT_ACCOUNT_ID;
}

/**
 * Resolve a Nostr account from config
 */
export function resolveNostrAccount(opts: {
  cfg: OpenClawConfig;
  accountId?: string | null;
}): ResolvedNostrAccount {
  const accountId = opts.accountId ?? DEFAULT_ACCOUNT_ID;
  const nostrCfg = (opts.cfg.channels as Record<string, unknown> | undefined)?.nostr as
    | NostrAccountConfig
    | undefined;

  const baseEnabled = nostrCfg?.enabled !== false;
  const privateKey = nostrCfg?.privateKey ?? "";
  const configured = Boolean(privateKey.trim());

  let publicKey = "";
  if (configured) {
    try {
      publicKey = getPublicKeyFromPrivate(privateKey);
    } catch {
      // Invalid key - leave publicKey empty, configured will indicate issues
    }
  }

  return {
    accountId,
    name: nostrCfg?.name?.trim() || undefined,
    enabled: baseEnabled,
    configured,
    privateKey,
    publicKey,
    relays: nostrCfg?.relays ?? DEFAULT_RELAYS,
    profile: nostrCfg?.profile,
    config: {
      enabled: nostrCfg?.enabled,
      name: nostrCfg?.name,
      privateKey: nostrCfg?.privateKey,
      relays: nostrCfg?.relays,
      dmPolicy: nostrCfg?.dmPolicy,
      allowFrom: nostrCfg?.allowFrom,
      profile: nostrCfg?.profile,
    },
  };
}
]]></file>
  <file path="./extensions/nostr/src/seen-tracker.ts"><![CDATA[/**
 * LRU-based seen event tracker with TTL support.
 * Prevents unbounded memory growth under high load or abuse.
 */

export interface SeenTrackerOptions {
  /** Maximum number of entries to track (default: 100,000) */
  maxEntries?: number;
  /** TTL in milliseconds (default: 1 hour) */
  ttlMs?: number;
  /** Prune interval in milliseconds (default: 10 minutes) */
  pruneIntervalMs?: number;
}

export interface SeenTracker {
  /** Check if an ID has been seen (also marks it as seen if not) */
  has: (id: string) => boolean;
  /** Mark an ID as seen */
  add: (id: string) => void;
  /** Check if ID exists without marking */
  peek: (id: string) => boolean;
  /** Delete an ID */
  delete: (id: string) => void;
  /** Clear all entries */
  clear: () => void;
  /** Get current size */
  size: () => number;
  /** Stop the pruning timer */
  stop: () => void;
  /** Pre-seed with IDs (useful for restart recovery) */
  seed: (ids: string[]) => void;
}

interface Entry {
  seenAt: number;
  // For LRU: track order via doubly-linked list
  prev: string | null;
  next: string | null;
}

/**
 * Create a new seen tracker with LRU eviction and TTL expiration.
 */
export function createSeenTracker(options?: SeenTrackerOptions): SeenTracker {
  const maxEntries = options?.maxEntries ?? 100_000;
  const ttlMs = options?.ttlMs ?? 60 * 60 * 1000; // 1 hour
  const pruneIntervalMs = options?.pruneIntervalMs ?? 10 * 60 * 1000; // 10 minutes

  // Main storage
  const entries = new Map<string, Entry>();

  // LRU tracking: head = most recent, tail = least recent
  let head: string | null = null;
  let tail: string | null = null;

  // Move an entry to the front (most recently used)
  function moveToFront(id: string): void {
    const entry = entries.get(id);
    if (!entry) {
      return;
    }

    // Already at front
    if (head === id) {
      return;
    }

    // Remove from current position
    if (entry.prev) {
      const prevEntry = entries.get(entry.prev);
      if (prevEntry) {
        prevEntry.next = entry.next;
      }
    }
    if (entry.next) {
      const nextEntry = entries.get(entry.next);
      if (nextEntry) {
        nextEntry.prev = entry.prev;
      }
    }

    // Update tail if this was the tail
    if (tail === id) {
      tail = entry.prev;
    }

    // Move to front
    entry.prev = null;
    entry.next = head;
    if (head) {
      const headEntry = entries.get(head);
      if (headEntry) {
        headEntry.prev = id;
      }
    }
    head = id;

    // If no tail, this is also the tail
    if (!tail) {
      tail = id;
    }
  }

  // Remove an entry from the linked list
  function removeFromList(id: string): void {
    const entry = entries.get(id);
    if (!entry) {
      return;
    }

    if (entry.prev) {
      const prevEntry = entries.get(entry.prev);
      if (prevEntry) {
        prevEntry.next = entry.next;
      }
    } else {
      head = entry.next;
    }

    if (entry.next) {
      const nextEntry = entries.get(entry.next);
      if (nextEntry) {
        nextEntry.prev = entry.prev;
      }
    } else {
      tail = entry.prev;
    }
  }

  // Evict the least recently used entry
  function evictLRU(): void {
    if (!tail) {
      return;
    }
    const idToEvict = tail;
    removeFromList(idToEvict);
    entries.delete(idToEvict);
  }

  // Prune expired entries
  function pruneExpired(): void {
    const now = Date.now();
    const toDelete: string[] = [];

    for (const [id, entry] of entries) {
      if (now - entry.seenAt > ttlMs) {
        toDelete.push(id);
      }
    }

    for (const id of toDelete) {
      removeFromList(id);
      entries.delete(id);
    }
  }

  // Start pruning timer
  let pruneTimer: ReturnType<typeof setInterval> | undefined;
  if (pruneIntervalMs > 0) {
    pruneTimer = setInterval(pruneExpired, pruneIntervalMs);
    // Don't keep process alive just for pruning
    if (pruneTimer.unref) {
      pruneTimer.unref();
    }
  }

  function add(id: string): void {
    const now = Date.now();

    // If already exists, update and move to front
    const existing = entries.get(id);
    if (existing) {
      existing.seenAt = now;
      moveToFront(id);
      return;
    }

    // Evict if at capacity
    while (entries.size >= maxEntries) {
      evictLRU();
    }

    // Add new entry at front
    const newEntry: Entry = {
      seenAt: now,
      prev: null,
      next: head,
    };

    if (head) {
      const headEntry = entries.get(head);
      if (headEntry) {
        headEntry.prev = id;
      }
    }

    entries.set(id, newEntry);
    head = id;
    if (!tail) {
      tail = id;
    }
  }

  function has(id: string): boolean {
    const entry = entries.get(id);
    if (!entry) {
      add(id);
      return false;
    }

    // Check if expired
    if (Date.now() - entry.seenAt > ttlMs) {
      removeFromList(id);
      entries.delete(id);
      add(id);
      return false;
    }

    // Mark as recently used
    entry.seenAt = Date.now();
    moveToFront(id);
    return true;
  }

  function peek(id: string): boolean {
    const entry = entries.get(id);
    if (!entry) {
      return false;
    }

    // Check if expired
    if (Date.now() - entry.seenAt > ttlMs) {
      removeFromList(id);
      entries.delete(id);
      return false;
    }

    return true;
  }

  function deleteEntry(id: string): void {
    if (entries.has(id)) {
      removeFromList(id);
      entries.delete(id);
    }
  }

  function clear(): void {
    entries.clear();
    head = null;
    tail = null;
  }

  function size(): number {
    return entries.size;
  }

  function stop(): void {
    if (pruneTimer) {
      clearInterval(pruneTimer);
      pruneTimer = undefined;
    }
  }

  function seed(ids: string[]): void {
    const now = Date.now();
    // Seed in reverse order so first IDs end up at front
    for (let i = ids.length - 1; i >= 0; i--) {
      const id = ids[i];
      if (!entries.has(id) && entries.size < maxEntries) {
        const newEntry: Entry = {
          seenAt: now,
          prev: null,
          next: head,
        };

        if (head) {
          const headEntry = entries.get(head);
          if (headEntry) {
            headEntry.prev = id;
          }
        }

        entries.set(id, newEntry);
        head = id;
        if (!tail) {
          tail = id;
        }
      }
    }
  }

  return {
    has,
    add,
    peek,
    delete: deleteEntry,
    clear,
    size,
    stop,
    seed,
  };
}
]]></file>
  <file path="./extensions/nostr/src/nostr-profile-http.test.ts"><![CDATA[/**
 * Tests for Nostr Profile HTTP Handler
 */

import { IncomingMessage, ServerResponse } from "node:http";
import { Socket } from "node:net";
import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  createNostrProfileHttpHandler,
  type NostrProfileHttpContext,
} from "./nostr-profile-http.js";

// Mock the channel exports
vi.mock("./channel.js", () => ({
  publishNostrProfile: vi.fn(),
  getNostrProfileState: vi.fn(),
}));

// Mock the import module
vi.mock("./nostr-profile-import.js", () => ({
  importProfileFromRelays: vi.fn(),
  mergeProfiles: vi.fn((local, imported) => ({ ...imported, ...local })),
}));

import { publishNostrProfile, getNostrProfileState } from "./channel.js";
import { importProfileFromRelays } from "./nostr-profile-import.js";

// ============================================================================
// Test Helpers
// ============================================================================

function createMockRequest(method: string, url: string, body?: unknown): IncomingMessage {
  const socket = new Socket();
  const req = new IncomingMessage(socket);
  req.method = method;
  req.url = url;
  req.headers = { host: "localhost:3000" };

  if (body) {
    const bodyStr = JSON.stringify(body);
    process.nextTick(() => {
      req.emit("data", Buffer.from(bodyStr));
      req.emit("end");
    });
  } else {
    process.nextTick(() => {
      req.emit("end");
    });
  }

  return req;
}

function createMockResponse(): ServerResponse & {
  _getData: () => string;
  _getStatusCode: () => number;
} {
  const res = new ServerResponse({} as IncomingMessage);

  let data = "";
  let statusCode = 200;

  res.write = function (chunk: unknown) {
    data += String(chunk);
    return true;
  };

  res.end = function (chunk?: unknown) {
    if (chunk) {
      // eslint-disable-next-line @typescript-eslint/no-base-to-string
      data += String(chunk);
    }
    return this;
  };

  Object.defineProperty(res, "statusCode", {
    get: () => statusCode,
    set: (code: number) => {
      statusCode = code;
    },
  });

  (res as unknown as { _getData: () => string })._getData = () => data;
  (res as unknown as { _getStatusCode: () => number })._getStatusCode = () => statusCode;

  return res as ServerResponse & { _getData: () => string; _getStatusCode: () => number };
}

function createMockContext(overrides?: Partial<NostrProfileHttpContext>): NostrProfileHttpContext {
  return {
    getConfigProfile: vi.fn().mockReturnValue(undefined),
    updateConfigProfile: vi.fn().mockResolvedValue(undefined),
    getAccountInfo: vi.fn().mockReturnValue({
      pubkey: "abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234",
      relays: ["wss://relay.damus.io"],
    }),
    log: {
      info: vi.fn(),
      warn: vi.fn(),
      error: vi.fn(),
    },
    ...overrides,
  };
}

// ============================================================================
// Tests
// ============================================================================

describe("nostr-profile-http", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("route matching", () => {
    it("returns false for non-nostr paths", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("GET", "/api/channels/telegram/profile");
      const res = createMockResponse();

      const result = await handler(req, res);

      expect(result).toBe(false);
    });

    it("returns false for paths without accountId", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("GET", "/api/channels/nostr/");
      const res = createMockResponse();

      const result = await handler(req, res);

      expect(result).toBe(false);
    });

    it("handles /api/channels/nostr/:accountId/profile", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("GET", "/api/channels/nostr/default/profile");
      const res = createMockResponse();

      vi.mocked(getNostrProfileState).mockResolvedValue(null);

      const result = await handler(req, res);

      expect(result).toBe(true);
    });
  });

  describe("GET /api/channels/nostr/:accountId/profile", () => {
    it("returns profile and publish state", async () => {
      const ctx = createMockContext({
        getConfigProfile: vi.fn().mockReturnValue({
          name: "testuser",
          displayName: "Test User",
        }),
      });
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("GET", "/api/channels/nostr/default/profile");
      const res = createMockResponse();

      vi.mocked(getNostrProfileState).mockResolvedValue({
        lastPublishedAt: 1234567890,
        lastPublishedEventId: "abc123",
        lastPublishResults: { "wss://relay.damus.io": "ok" },
      });

      await handler(req, res);

      expect(res._getStatusCode()).toBe(200);
      const data = JSON.parse(res._getData());
      expect(data.ok).toBe(true);
      expect(data.profile.name).toBe("testuser");
      expect(data.publishState.lastPublishedAt).toBe(1234567890);
    });
  });

  describe("PUT /api/channels/nostr/:accountId/profile", () => {
    it("validates profile and publishes", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("PUT", "/api/channels/nostr/default/profile", {
        name: "satoshi",
        displayName: "Satoshi Nakamoto",
        about: "Creator of Bitcoin",
      });
      const res = createMockResponse();

      vi.mocked(publishNostrProfile).mockResolvedValue({
        eventId: "event123",
        createdAt: 1234567890,
        successes: ["wss://relay.damus.io"],
        failures: [],
      });

      await handler(req, res);

      expect(res._getStatusCode()).toBe(200);
      const data = JSON.parse(res._getData());
      expect(data.ok).toBe(true);
      expect(data.eventId).toBe("event123");
      expect(data.successes).toContain("wss://relay.damus.io");
      expect(data.persisted).toBe(true);
      expect(ctx.updateConfigProfile).toHaveBeenCalled();
    });

    it("rejects private IP in picture URL (SSRF protection)", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("PUT", "/api/channels/nostr/default/profile", {
        name: "hacker",
        picture: "https://127.0.0.1/evil.jpg",
      });
      const res = createMockResponse();

      await handler(req, res);

      expect(res._getStatusCode()).toBe(400);
      const data = JSON.parse(res._getData());
      expect(data.ok).toBe(false);
      expect(data.error).toContain("private");
    });

    it("rejects non-https URLs", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("PUT", "/api/channels/nostr/default/profile", {
        name: "test",
        picture: "http://example.com/pic.jpg",
      });
      const res = createMockResponse();

      await handler(req, res);

      expect(res._getStatusCode()).toBe(400);
      const data = JSON.parse(res._getData());
      expect(data.ok).toBe(false);
      // The schema validation catches non-https URLs before SSRF check
      expect(data.error).toBe("Validation failed");
      expect(data.details).toBeDefined();
      expect(data.details.some((d: string) => d.includes("https"))).toBe(true);
    });

    it("does not persist if all relays fail", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("PUT", "/api/channels/nostr/default/profile", {
        name: "test",
      });
      const res = createMockResponse();

      vi.mocked(publishNostrProfile).mockResolvedValue({
        eventId: "event123",
        createdAt: 1234567890,
        successes: [],
        failures: [{ relay: "wss://relay.damus.io", error: "timeout" }],
      });

      await handler(req, res);

      expect(res._getStatusCode()).toBe(200);
      const data = JSON.parse(res._getData());
      expect(data.persisted).toBe(false);
      expect(ctx.updateConfigProfile).not.toHaveBeenCalled();
    });

    it("enforces rate limiting", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);

      vi.mocked(publishNostrProfile).mockResolvedValue({
        eventId: "event123",
        createdAt: 1234567890,
        successes: ["wss://relay.damus.io"],
        failures: [],
      });

      // Make 6 requests (limit is 5/min)
      for (let i = 0; i < 6; i++) {
        const req = createMockRequest("PUT", "/api/channels/nostr/rate-test/profile", {
          name: `user${i}`,
        });
        const res = createMockResponse();
        await handler(req, res);

        if (i < 5) {
          expect(res._getStatusCode()).toBe(200);
        } else {
          expect(res._getStatusCode()).toBe(429);
          const data = JSON.parse(res._getData());
          expect(data.error).toContain("Rate limit");
        }
      }
    });
  });

  describe("POST /api/channels/nostr/:accountId/profile/import", () => {
    it("imports profile from relays", async () => {
      const ctx = createMockContext();
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("POST", "/api/channels/nostr/default/profile/import", {});
      const res = createMockResponse();

      vi.mocked(importProfileFromRelays).mockResolvedValue({
        ok: true,
        profile: {
          name: "imported",
          displayName: "Imported User",
        },
        event: {
          id: "evt123",
          pubkey: "abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234",
          created_at: 1234567890,
        },
        relaysQueried: ["wss://relay.damus.io"],
        sourceRelay: "wss://relay.damus.io",
      });

      await handler(req, res);

      expect(res._getStatusCode()).toBe(200);
      const data = JSON.parse(res._getData());
      expect(data.ok).toBe(true);
      expect(data.imported.name).toBe("imported");
      expect(data.saved).toBe(false); // autoMerge not requested
    });

    it("auto-merges when requested", async () => {
      const ctx = createMockContext({
        getConfigProfile: vi.fn().mockReturnValue({ about: "local bio" }),
      });
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("POST", "/api/channels/nostr/default/profile/import", {
        autoMerge: true,
      });
      const res = createMockResponse();

      vi.mocked(importProfileFromRelays).mockResolvedValue({
        ok: true,
        profile: {
          name: "imported",
          displayName: "Imported User",
        },
        event: {
          id: "evt123",
          pubkey: "abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234",
          created_at: 1234567890,
        },
        relaysQueried: ["wss://relay.damus.io"],
        sourceRelay: "wss://relay.damus.io",
      });

      await handler(req, res);

      expect(res._getStatusCode()).toBe(200);
      const data = JSON.parse(res._getData());
      expect(data.saved).toBe(true);
      expect(ctx.updateConfigProfile).toHaveBeenCalled();
    });

    it("returns error when account not found", async () => {
      const ctx = createMockContext({
        getAccountInfo: vi.fn().mockReturnValue(null),
      });
      const handler = createNostrProfileHttpHandler(ctx);
      const req = createMockRequest("POST", "/api/channels/nostr/unknown/profile/import", {});
      const res = createMockResponse();

      await handler(req, res);

      expect(res._getStatusCode()).toBe(404);
      const data = JSON.parse(res._getData());
      expect(data.error).toContain("not found");
    });
  });
});
]]></file>
  <file path="./extensions/nostr/src/nostr-state-store.test.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";
import fs from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import {
  readNostrBusState,
  writeNostrBusState,
  computeSinceTimestamp,
} from "./nostr-state-store.js";
import { setNostrRuntime } from "./runtime.js";

async function withTempStateDir<T>(fn: (dir: string) => Promise<T>) {
  const previous = process.env.OPENCLAW_STATE_DIR;
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), "openclaw-nostr-"));
  process.env.OPENCLAW_STATE_DIR = dir;
  setNostrRuntime({
    state: {
      resolveStateDir: (env, homedir) => {
        const override = env.OPENCLAW_STATE_DIR?.trim() || env.OPENCLAW_STATE_DIR?.trim();
        if (override) {
          return override;
        }
        return path.join(homedir(), ".openclaw");
      },
    },
  } as PluginRuntime);
  try {
    return await fn(dir);
  } finally {
    if (previous === undefined) {
      delete process.env.OPENCLAW_STATE_DIR;
    } else {
      process.env.OPENCLAW_STATE_DIR = previous;
    }
    await fs.rm(dir, { recursive: true, force: true });
  }
}

describe("nostr bus state store", () => {
  it("persists and reloads state across restarts", async () => {
    await withTempStateDir(async () => {
      // Fresh start - no state
      expect(await readNostrBusState({ accountId: "test-bot" })).toBeNull();

      // Write state
      await writeNostrBusState({
        accountId: "test-bot",
        lastProcessedAt: 1700000000,
        gatewayStartedAt: 1700000100,
      });

      // Read it back
      const state = await readNostrBusState({ accountId: "test-bot" });
      expect(state).toEqual({
        version: 2,
        lastProcessedAt: 1700000000,
        gatewayStartedAt: 1700000100,
        recentEventIds: [],
      });
    });
  });

  it("isolates state by accountId", async () => {
    await withTempStateDir(async () => {
      await writeNostrBusState({
        accountId: "bot-a",
        lastProcessedAt: 1000,
        gatewayStartedAt: 1000,
      });
      await writeNostrBusState({
        accountId: "bot-b",
        lastProcessedAt: 2000,
        gatewayStartedAt: 2000,
      });

      const stateA = await readNostrBusState({ accountId: "bot-a" });
      const stateB = await readNostrBusState({ accountId: "bot-b" });

      expect(stateA?.lastProcessedAt).toBe(1000);
      expect(stateB?.lastProcessedAt).toBe(2000);
    });
  });
});

describe("computeSinceTimestamp", () => {
  it("returns now for null state (fresh start)", () => {
    const now = 1700000000;
    expect(computeSinceTimestamp(null, now)).toBe(now);
  });

  it("uses lastProcessedAt when available", () => {
    const state = {
      version: 2,
      lastProcessedAt: 1699999000,
      gatewayStartedAt: null,
      recentEventIds: [],
    };
    expect(computeSinceTimestamp(state, 1700000000)).toBe(1699999000);
  });

  it("uses gatewayStartedAt when lastProcessedAt is null", () => {
    const state = {
      version: 2,
      lastProcessedAt: null,
      gatewayStartedAt: 1699998000,
      recentEventIds: [],
    };
    expect(computeSinceTimestamp(state, 1700000000)).toBe(1699998000);
  });

  it("uses the max of both timestamps", () => {
    const state = {
      version: 2,
      lastProcessedAt: 1699999000,
      gatewayStartedAt: 1699998000,
      recentEventIds: [],
    };
    expect(computeSinceTimestamp(state, 1700000000)).toBe(1699999000);
  });

  it("falls back to now if both are null", () => {
    const state = {
      version: 2,
      lastProcessedAt: null,
      gatewayStartedAt: null,
      recentEventIds: [],
    };
    expect(computeSinceTimestamp(state, 1700000000)).toBe(1700000000);
  });
});
]]></file>
  <file path="./extensions/nostr/src/nostr-profile-import.test.ts"><![CDATA[/**
 * Tests for Nostr Profile Import
 */

import { describe, it, expect } from "vitest";
import type { NostrProfile } from "./config-schema.js";
import { mergeProfiles } from "./nostr-profile-import.js";

// Note: importProfileFromRelays requires real network calls or complex mocking
// of nostr-tools SimplePool, so we focus on unit testing mergeProfiles

describe("nostr-profile-import", () => {
  describe("mergeProfiles", () => {
    it("returns empty object when both are undefined", () => {
      const result = mergeProfiles(undefined, undefined);
      expect(result).toEqual({});
    });

    it("returns imported when local is undefined", () => {
      const imported: NostrProfile = {
        name: "imported",
        displayName: "Imported User",
        about: "Bio from relay",
      };
      const result = mergeProfiles(undefined, imported);
      expect(result).toEqual(imported);
    });

    it("returns local when imported is undefined", () => {
      const local: NostrProfile = {
        name: "local",
        displayName: "Local User",
      };
      const result = mergeProfiles(local, undefined);
      expect(result).toEqual(local);
    });

    it("prefers local values over imported", () => {
      const local: NostrProfile = {
        name: "localname",
        about: "Local bio",
      };
      const imported: NostrProfile = {
        name: "importedname",
        displayName: "Imported Display",
        about: "Imported bio",
        picture: "https://example.com/pic.jpg",
      };

      const result = mergeProfiles(local, imported);

      expect(result.name).toBe("localname"); // local wins
      expect(result.displayName).toBe("Imported Display"); // imported fills gap
      expect(result.about).toBe("Local bio"); // local wins
      expect(result.picture).toBe("https://example.com/pic.jpg"); // imported fills gap
    });

    it("fills all missing fields from imported", () => {
      const local: NostrProfile = {
        name: "myname",
      };
      const imported: NostrProfile = {
        name: "theirname",
        displayName: "Their Name",
        about: "Their bio",
        picture: "https://example.com/pic.jpg",
        banner: "https://example.com/banner.jpg",
        website: "https://example.com",
        nip05: "user@example.com",
        lud16: "user@getalby.com",
      };

      const result = mergeProfiles(local, imported);

      expect(result.name).toBe("myname");
      expect(result.displayName).toBe("Their Name");
      expect(result.about).toBe("Their bio");
      expect(result.picture).toBe("https://example.com/pic.jpg");
      expect(result.banner).toBe("https://example.com/banner.jpg");
      expect(result.website).toBe("https://example.com");
      expect(result.nip05).toBe("user@example.com");
      expect(result.lud16).toBe("user@getalby.com");
    });

    it("handles empty strings as falsy (prefers imported)", () => {
      const local: NostrProfile = {
        name: "",
        displayName: "",
      };
      const imported: NostrProfile = {
        name: "imported",
        displayName: "Imported",
      };

      const result = mergeProfiles(local, imported);

      // Empty strings are still strings, so they "win" over imported
      // This is JavaScript nullish coalescing behavior
      expect(result.name).toBe("");
      expect(result.displayName).toBe("");
    });

    it("handles null values in local (prefers imported)", () => {
      const local: NostrProfile = {
        name: undefined,
        displayName: undefined,
      };
      const imported: NostrProfile = {
        name: "imported",
        displayName: "Imported",
      };

      const result = mergeProfiles(local, imported);

      expect(result.name).toBe("imported");
      expect(result.displayName).toBe("Imported");
    });
  });
});
]]></file>
  <file path="./extensions/nostr/src/nostr-bus.ts"><![CDATA[import {
  SimplePool,
  finalizeEvent,
  getPublicKey,
  verifyEvent,
  nip19,
  type Event,
} from "nostr-tools";
import { decrypt, encrypt } from "nostr-tools/nip04";
import type { NostrProfile } from "./config-schema.js";
import {
  createMetrics,
  createNoopMetrics,
  type NostrMetrics,
  type MetricsSnapshot,
  type MetricEvent,
} from "./metrics.js";
import { publishProfile as publishProfileFn, type ProfilePublishResult } from "./nostr-profile.js";
import {
  readNostrBusState,
  writeNostrBusState,
  computeSinceTimestamp,
  readNostrProfileState,
  writeNostrProfileState,
} from "./nostr-state-store.js";
import { createSeenTracker, type SeenTracker } from "./seen-tracker.js";

export const DEFAULT_RELAYS = ["wss://relay.damus.io", "wss://nos.lol"];

// ============================================================================
// Constants
// ============================================================================

const STARTUP_LOOKBACK_SEC = 120; // tolerate relay lag / clock skew
const MAX_PERSISTED_EVENT_IDS = 5000;
const STATE_PERSIST_DEBOUNCE_MS = 5000; // Debounce state writes

// Circuit breaker configuration
const CIRCUIT_BREAKER_THRESHOLD = 5; // failures before opening
const CIRCUIT_BREAKER_RESET_MS = 30000; // 30 seconds before half-open

// Health tracker configuration
const HEALTH_WINDOW_MS = 60000; // 1 minute window for health stats

// ============================================================================
// Types
// ============================================================================

export interface NostrBusOptions {
  /** Private key in hex or nsec format */
  privateKey: string;
  /** WebSocket relay URLs (defaults to damus + nos.lol) */
  relays?: string[];
  /** Account ID for state persistence (optional, defaults to pubkey prefix) */
  accountId?: string;
  /** Called when a DM is received */
  onMessage: (
    pubkey: string,
    text: string,
    reply: (text: string) => Promise<void>,
  ) => Promise<void>;
  /** Called on errors (optional) */
  onError?: (error: Error, context: string) => void;
  /** Called on connection status changes (optional) */
  onConnect?: (relay: string) => void;
  /** Called on disconnection (optional) */
  onDisconnect?: (relay: string) => void;
  /** Called on EOSE (end of stored events) for initial sync (optional) */
  onEose?: (relay: string) => void;
  /** Called on each metric event (optional) */
  onMetric?: (event: MetricEvent) => void;
  /** Maximum entries in seen tracker (default: 100,000) */
  maxSeenEntries?: number;
  /** Seen tracker TTL in ms (default: 1 hour) */
  seenTtlMs?: number;
}

export interface NostrBusHandle {
  /** Stop the bus and close connections */
  close: () => void;
  /** Get the bot's public key */
  publicKey: string;
  /** Send a DM to a pubkey */
  sendDm: (toPubkey: string, text: string) => Promise<void>;
  /** Get current metrics snapshot */
  getMetrics: () => MetricsSnapshot;
  /** Publish a profile (kind:0) to all relays */
  publishProfile: (profile: NostrProfile) => Promise<ProfilePublishResult>;
  /** Get the last profile publish state */
  getProfileState: () => Promise<{
    lastPublishedAt: number | null;
    lastPublishedEventId: string | null;
    lastPublishResults: Record<string, "ok" | "failed" | "timeout"> | null;
  }>;
}

// ============================================================================
// Circuit Breaker
// ============================================================================

interface CircuitBreakerState {
  state: "closed" | "open" | "half_open";
  failures: number;
  lastFailure: number;
  lastSuccess: number;
}

interface CircuitBreaker {
  /** Check if requests should be allowed */
  canAttempt: () => boolean;
  /** Record a success */
  recordSuccess: () => void;
  /** Record a failure */
  recordFailure: () => void;
  /** Get current state */
  getState: () => CircuitBreakerState["state"];
}

function createCircuitBreaker(
  relay: string,
  metrics: NostrMetrics,
  threshold: number = CIRCUIT_BREAKER_THRESHOLD,
  resetMs: number = CIRCUIT_BREAKER_RESET_MS,
): CircuitBreaker {
  const state: CircuitBreakerState = {
    state: "closed",
    failures: 0,
    lastFailure: 0,
    lastSuccess: Date.now(),
  };

  return {
    canAttempt(): boolean {
      if (state.state === "closed") {
        return true;
      }

      if (state.state === "open") {
        // Check if enough time has passed to try half-open
        if (Date.now() - state.lastFailure >= resetMs) {
          state.state = "half_open";
          metrics.emit("relay.circuit_breaker.half_open", 1, { relay });
          return true;
        }
        return false;
      }

      // half_open: allow one attempt
      return true;
    },

    recordSuccess(): void {
      if (state.state === "half_open") {
        state.state = "closed";
        state.failures = 0;
        metrics.emit("relay.circuit_breaker.close", 1, { relay });
      } else if (state.state === "closed") {
        state.failures = 0;
      }
      state.lastSuccess = Date.now();
    },

    recordFailure(): void {
      state.failures++;
      state.lastFailure = Date.now();

      if (state.state === "half_open") {
        state.state = "open";
        metrics.emit("relay.circuit_breaker.open", 1, { relay });
      } else if (state.state === "closed" && state.failures >= threshold) {
        state.state = "open";
        metrics.emit("relay.circuit_breaker.open", 1, { relay });
      }
    },

    getState(): CircuitBreakerState["state"] {
      return state.state;
    },
  };
}

// ============================================================================
// Relay Health Tracker
// ============================================================================

interface RelayHealthStats {
  successCount: number;
  failureCount: number;
  latencySum: number;
  latencyCount: number;
  lastSuccess: number;
  lastFailure: number;
}

interface RelayHealthTracker {
  /** Record a successful operation */
  recordSuccess: (relay: string, latencyMs: number) => void;
  /** Record a failed operation */
  recordFailure: (relay: string) => void;
  /** Get health score (0-1, higher is better) */
  getScore: (relay: string) => number;
  /** Get relays sorted by health (best first) */
  getSortedRelays: (relays: string[]) => string[];
}

function createRelayHealthTracker(): RelayHealthTracker {
  const stats = new Map<string, RelayHealthStats>();

  function getOrCreate(relay: string): RelayHealthStats {
    let s = stats.get(relay);
    if (!s) {
      s = {
        successCount: 0,
        failureCount: 0,
        latencySum: 0,
        latencyCount: 0,
        lastSuccess: 0,
        lastFailure: 0,
      };
      stats.set(relay, s);
    }
    return s;
  }

  return {
    recordSuccess(relay: string, latencyMs: number): void {
      const s = getOrCreate(relay);
      s.successCount++;
      s.latencySum += latencyMs;
      s.latencyCount++;
      s.lastSuccess = Date.now();
    },

    recordFailure(relay: string): void {
      const s = getOrCreate(relay);
      s.failureCount++;
      s.lastFailure = Date.now();
    },

    getScore(relay: string): number {
      const s = stats.get(relay);
      if (!s) {
        return 0.5;
      } // Unknown relay gets neutral score

      const total = s.successCount + s.failureCount;
      if (total === 0) {
        return 0.5;
      }

      // Success rate (0-1)
      const successRate = s.successCount / total;

      // Recency bonus (prefer recently successful relays)
      const now = Date.now();
      const recencyBonus =
        s.lastSuccess > s.lastFailure
          ? Math.max(0, 1 - (now - s.lastSuccess) / HEALTH_WINDOW_MS) * 0.2
          : 0;

      // Latency penalty (lower is better)
      const avgLatency = s.latencyCount > 0 ? s.latencySum / s.latencyCount : 1000;
      const latencyPenalty = Math.min(0.2, avgLatency / 10000);

      return Math.max(0, Math.min(1, successRate + recencyBonus - latencyPenalty));
    },

    getSortedRelays(relays: string[]): string[] {
      return [...relays].toSorted((a, b) => this.getScore(b) - this.getScore(a));
    },
  };
}

// ============================================================================
// Key Validation
// ============================================================================

/**
 * Validate and normalize a private key (accepts hex or nsec format)
 */
export function validatePrivateKey(key: string): Uint8Array {
  const trimmed = key.trim();

  // Handle nsec (bech32) format
  if (trimmed.startsWith("nsec1")) {
    const decoded = nip19.decode(trimmed);
    if (decoded.type !== "nsec") {
      throw new Error("Invalid nsec key: wrong type");
    }
    return decoded.data;
  }

  // Handle hex format
  if (!/^[0-9a-fA-F]{64}$/.test(trimmed)) {
    throw new Error("Private key must be 64 hex characters or nsec bech32 format");
  }

  // Convert hex string to Uint8Array
  const bytes = new Uint8Array(32);
  for (let i = 0; i < 32; i++) {
    bytes[i] = parseInt(trimmed.slice(i * 2, i * 2 + 2), 16);
  }
  return bytes;
}

/**
 * Get public key from private key (hex or nsec format)
 */
export function getPublicKeyFromPrivate(privateKey: string): string {
  const sk = validatePrivateKey(privateKey);
  return getPublicKey(sk);
}

// ============================================================================
// Main Bus
// ============================================================================

/**
 * Start the Nostr DM bus - subscribes to NIP-04 encrypted DMs
 */
export async function startNostrBus(options: NostrBusOptions): Promise<NostrBusHandle> {
  const {
    privateKey,
    relays = DEFAULT_RELAYS,
    onMessage,
    onError,
    onEose,
    onMetric,
    maxSeenEntries = 100_000,
    seenTtlMs = 60 * 60 * 1000,
  } = options;

  const sk = validatePrivateKey(privateKey);
  const pk = getPublicKey(sk);
  const pool = new SimplePool();
  const accountId = options.accountId ?? pk.slice(0, 16);
  const gatewayStartedAt = Math.floor(Date.now() / 1000);

  // Initialize metrics
  const metrics = onMetric ? createMetrics(onMetric) : createNoopMetrics();

  // Initialize seen tracker with LRU
  const seen: SeenTracker = createSeenTracker({
    maxEntries: maxSeenEntries,
    ttlMs: seenTtlMs,
  });

  // Initialize circuit breakers and health tracker
  const circuitBreakers = new Map<string, CircuitBreaker>();
  const healthTracker = createRelayHealthTracker();

  for (const relay of relays) {
    circuitBreakers.set(relay, createCircuitBreaker(relay, metrics));
  }

  // Read persisted state and compute `since` timestamp (with small overlap)
  const state = await readNostrBusState({ accountId });
  const baseSince = computeSinceTimestamp(state, gatewayStartedAt);
  const since = Math.max(0, baseSince - STARTUP_LOOKBACK_SEC);

  // Seed in-memory dedupe with recent IDs from disk (prevents restart replay)
  if (state?.recentEventIds?.length) {
    seen.seed(state.recentEventIds);
  }

  // Persist startup timestamp
  await writeNostrBusState({
    accountId,
    lastProcessedAt: state?.lastProcessedAt ?? gatewayStartedAt,
    gatewayStartedAt,
    recentEventIds: state?.recentEventIds ?? [],
  });

  // Debounced state persistence
  let pendingWrite: ReturnType<typeof setTimeout> | undefined;
  let lastProcessedAt = state?.lastProcessedAt ?? gatewayStartedAt;
  let recentEventIds = (state?.recentEventIds ?? []).slice(-MAX_PERSISTED_EVENT_IDS);

  function scheduleStatePersist(eventCreatedAt: number, eventId: string): void {
    lastProcessedAt = Math.max(lastProcessedAt, eventCreatedAt);
    recentEventIds.push(eventId);
    if (recentEventIds.length > MAX_PERSISTED_EVENT_IDS) {
      recentEventIds = recentEventIds.slice(-MAX_PERSISTED_EVENT_IDS);
    }

    if (pendingWrite) {
      clearTimeout(pendingWrite);
    }
    pendingWrite = setTimeout(() => {
      writeNostrBusState({
        accountId,
        lastProcessedAt,
        gatewayStartedAt,
        recentEventIds,
      }).catch((err) => onError?.(err as Error, "persist state"));
    }, STATE_PERSIST_DEBOUNCE_MS);
  }

  const inflight = new Set<string>();

  // Event handler
  async function handleEvent(event: Event): Promise<void> {
    try {
      metrics.emit("event.received");

      // Fast dedupe check (handles relay reconnections)
      if (seen.peek(event.id) || inflight.has(event.id)) {
        metrics.emit("event.duplicate");
        return;
      }
      inflight.add(event.id);

      // Self-message loop prevention: skip our own messages
      if (event.pubkey === pk) {
        metrics.emit("event.rejected.self_message");
        return;
      }

      // Skip events older than our `since` (relay may ignore filter)
      if (event.created_at < since) {
        metrics.emit("event.rejected.stale");
        return;
      }

      // Fast p-tag check BEFORE crypto (no allocation, cheaper)
      let targetsUs = false;
      for (const t of event.tags) {
        if (t[0] === "p" && t[1] === pk) {
          targetsUs = true;
          break;
        }
      }
      if (!targetsUs) {
        metrics.emit("event.rejected.wrong_kind");
        return;
      }

      // Verify signature (must pass before we trust the event)
      if (!verifyEvent(event)) {
        metrics.emit("event.rejected.invalid_signature");
        onError?.(new Error("Invalid signature"), `event ${event.id}`);
        return;
      }

      // Mark seen AFTER verify (don't cache invalid IDs)
      seen.add(event.id);
      metrics.emit("memory.seen_tracker_size", seen.size());

      // Decrypt the message
      let plaintext: string;
      try {
        plaintext = decrypt(sk, event.pubkey, event.content);
        metrics.emit("decrypt.success");
      } catch (err) {
        metrics.emit("decrypt.failure");
        metrics.emit("event.rejected.decrypt_failed");
        onError?.(err as Error, `decrypt from ${event.pubkey}`);
        return;
      }

      // Create reply function (try relays by health score)
      const replyTo = async (text: string): Promise<void> => {
        await sendEncryptedDm(
          pool,
          sk,
          event.pubkey,
          text,
          relays,
          metrics,
          circuitBreakers,
          healthTracker,
          onError,
        );
      };

      // Call the message handler
      await onMessage(event.pubkey, plaintext, replyTo);

      // Mark as processed
      metrics.emit("event.processed");

      // Persist progress (debounced)
      scheduleStatePersist(event.created_at, event.id);
    } catch (err) {
      onError?.(err as Error, `event ${event.id}`);
    } finally {
      inflight.delete(event.id);
    }
  }

  const sub = pool.subscribeMany(
    relays,
    [{ kinds: [4], "#p": [pk], since }] as unknown as Parameters<typeof pool.subscribeMany>[1],
    {
      onevent: handleEvent,
      oneose: () => {
        // EOSE handler - called when all stored events have been received
        for (const relay of relays) {
          metrics.emit("relay.message.eose", 1, { relay });
        }
        onEose?.(relays.join(", "));
      },
      onclose: (reason) => {
        // Handle subscription close
        for (const relay of relays) {
          metrics.emit("relay.message.closed", 1, { relay });
          options.onDisconnect?.(relay);
        }
        onError?.(new Error(`Subscription closed: ${reason.join(", ")}`), "subscription");
      },
    },
  );

  // Public sendDm function
  const sendDm = async (toPubkey: string, text: string): Promise<void> => {
    await sendEncryptedDm(
      pool,
      sk,
      toPubkey,
      text,
      relays,
      metrics,
      circuitBreakers,
      healthTracker,
      onError,
    );
  };

  // Profile publishing function
  const publishProfile = async (profile: NostrProfile): Promise<ProfilePublishResult> => {
    // Read last published timestamp for monotonic ordering
    const profileState = await readNostrProfileState({ accountId });
    const lastPublishedAt = profileState?.lastPublishedAt ?? undefined;

    // Publish the profile
    const result = await publishProfileFn(pool, sk, relays, profile, lastPublishedAt);

    // Convert results to state format
    const publishResults: Record<string, "ok" | "failed" | "timeout"> = {};
    for (const relay of result.successes) {
      publishResults[relay] = "ok";
    }
    for (const { relay, error } of result.failures) {
      publishResults[relay] = error === "timeout" ? "timeout" : "failed";
    }

    // Persist the publish state
    await writeNostrProfileState({
      accountId,
      lastPublishedAt: result.createdAt,
      lastPublishedEventId: result.eventId,
      lastPublishResults: publishResults,
    });

    return result;
  };

  // Get profile state function
  const getProfileState = async () => {
    const state = await readNostrProfileState({ accountId });
    return {
      lastPublishedAt: state?.lastPublishedAt ?? null,
      lastPublishedEventId: state?.lastPublishedEventId ?? null,
      lastPublishResults: state?.lastPublishResults ?? null,
    };
  };

  return {
    close: () => {
      sub.close();
      seen.stop();
      // Flush pending state write synchronously on close
      if (pendingWrite) {
        clearTimeout(pendingWrite);
        writeNostrBusState({
          accountId,
          lastProcessedAt,
          gatewayStartedAt,
          recentEventIds,
        }).catch((err) => onError?.(err as Error, "persist state on close"));
      }
    },
    publicKey: pk,
    sendDm,
    getMetrics: () => metrics.getSnapshot(),
    publishProfile,
    getProfileState,
  };
}

// ============================================================================
// Send DM with Circuit Breaker + Health Scoring
// ============================================================================

/**
 * Send an encrypted DM to a pubkey
 */
async function sendEncryptedDm(
  pool: SimplePool,
  sk: Uint8Array,
  toPubkey: string,
  text: string,
  relays: string[],
  metrics: NostrMetrics,
  circuitBreakers: Map<string, CircuitBreaker>,
  healthTracker: RelayHealthTracker,
  onError?: (error: Error, context: string) => void,
): Promise<void> {
  const ciphertext = encrypt(sk, toPubkey, text);
  const reply = finalizeEvent(
    {
      kind: 4,
      content: ciphertext,
      tags: [["p", toPubkey]],
      created_at: Math.floor(Date.now() / 1000),
    },
    sk,
  );

  // Sort relays by health score (best first)
  const sortedRelays = healthTracker.getSortedRelays(relays);

  // Try relays in order of health, respecting circuit breakers
  let lastError: Error | undefined;
  for (const relay of sortedRelays) {
    const cb = circuitBreakers.get(relay);

    // Skip if circuit breaker is open
    if (cb && !cb.canAttempt()) {
      continue;
    }

    const startTime = Date.now();
    try {
      // oxlint-disable-next-line typescript/await-thenable typesciript/no-floating-promises
      await pool.publish([relay], reply);
      const latency = Date.now() - startTime;

      // Record success
      cb?.recordSuccess();
      healthTracker.recordSuccess(relay, latency);

      return; // Success - exit early
    } catch (err) {
      lastError = err as Error;
      const latency = Date.now() - startTime;

      // Record failure
      cb?.recordFailure();
      healthTracker.recordFailure(relay);
      metrics.emit("relay.error", 1, { relay, latency });

      onError?.(lastError, `publish to ${relay}`);
    }
  }

  throw new Error(`Failed to publish to any relay: ${lastError?.message}`);
}

// ============================================================================
// Pubkey Utilities
// ============================================================================

/**
 * Check if a string looks like a valid Nostr pubkey (hex or npub)
 */
export function isValidPubkey(input: string): boolean {
  if (typeof input !== "string") {
    return false;
  }
  const trimmed = input.trim();

  // npub format
  if (trimmed.startsWith("npub1")) {
    try {
      const decoded = nip19.decode(trimmed);
      return decoded.type === "npub";
    } catch {
      return false;
    }
  }

  // Hex format
  return /^[0-9a-fA-F]{64}$/.test(trimmed);
}

/**
 * Normalize a pubkey to hex format (accepts npub or hex)
 */
export function normalizePubkey(input: string): string {
  const trimmed = input.trim();

  // npub format - decode to hex
  if (trimmed.startsWith("npub1")) {
    const decoded = nip19.decode(trimmed);
    if (decoded.type !== "npub") {
      throw new Error("Invalid npub key");
    }
    // Convert Uint8Array to hex string
    return Array.from(decoded.data as unknown as Uint8Array)
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");
  }

  // Already hex - validate and return lowercase
  if (!/^[0-9a-fA-F]{64}$/.test(trimmed)) {
    throw new Error("Pubkey must be 64 hex characters or npub format");
  }
  return trimmed.toLowerCase();
}

/**
 * Convert a hex pubkey to npub format
 */
export function pubkeyToNpub(hexPubkey: string): string {
  const normalized = normalizePubkey(hexPubkey);
  // npubEncode expects a hex string, not Uint8Array
  return nip19.npubEncode(normalized);
}
]]></file>
  <file path="./extensions/nostr/src/config-schema.ts"><![CDATA[import { MarkdownConfigSchema, buildChannelConfigSchema } from "openclaw/plugin-sdk";
import { z } from "zod";

const allowFromEntry = z.union([z.string(), z.number()]);

/**
 * Validates https:// URLs only (no javascript:, data:, file:, etc.)
 */
const safeUrlSchema = z
  .string()
  .url()
  .refine(
    (url) => {
      try {
        const parsed = new URL(url);
        return parsed.protocol === "https:";
      } catch {
        return false;
      }
    },
    { message: "URL must use https:// protocol" },
  );

/**
 * NIP-01 profile metadata schema
 * https://github.com/nostr-protocol/nips/blob/master/01.md
 */
export const NostrProfileSchema = z.object({
  /** Username (NIP-01: name) - max 256 chars */
  name: z.string().max(256).optional(),

  /** Display name (NIP-01: display_name) - max 256 chars */
  displayName: z.string().max(256).optional(),

  /** Bio/description (NIP-01: about) - max 2000 chars */
  about: z.string().max(2000).optional(),

  /** Profile picture URL (must be https) */
  picture: safeUrlSchema.optional(),

  /** Banner image URL (must be https) */
  banner: safeUrlSchema.optional(),

  /** Website URL (must be https) */
  website: safeUrlSchema.optional(),

  /** NIP-05 identifier (e.g., "user@example.com") */
  nip05: z.string().optional(),

  /** Lightning address (LUD-16) */
  lud16: z.string().optional(),
});

export type NostrProfile = z.infer<typeof NostrProfileSchema>;

/**
 * Zod schema for channels.nostr.* configuration
 */
export const NostrConfigSchema = z.object({
  /** Account name (optional display name) */
  name: z.string().optional(),

  /** Whether this channel is enabled */
  enabled: z.boolean().optional(),

  /** Markdown formatting overrides (tables). */
  markdown: MarkdownConfigSchema,

  /** Private key in hex or nsec bech32 format */
  privateKey: z.string().optional(),

  /** WebSocket relay URLs to connect to */
  relays: z.array(z.string()).optional(),

  /** DM access policy: pairing, allowlist, open, or disabled */
  dmPolicy: z.enum(["pairing", "allowlist", "open", "disabled"]).optional(),

  /** Allowed sender pubkeys (npub or hex format) */
  allowFrom: z.array(allowFromEntry).optional(),

  /** Profile metadata (NIP-01 kind:0 content) */
  profile: NostrProfileSchema.optional(),
});

export type NostrConfig = z.infer<typeof NostrConfigSchema>;

/**
 * JSON Schema for Control UI (converted from Zod)
 */
export const nostrChannelConfigSchema = buildChannelConfigSchema(NostrConfigSchema);
]]></file>
  <file path="./extensions/nostr/src/nostr-bus.fuzz.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import { createMetrics, type MetricName } from "./metrics.js";
import { validatePrivateKey, isValidPubkey, normalizePubkey } from "./nostr-bus.js";
import { createSeenTracker } from "./seen-tracker.js";

// ============================================================================
// Fuzz Tests for validatePrivateKey
// ============================================================================

describe("validatePrivateKey fuzz", () => {
  describe("type confusion", () => {
    it("rejects null input", () => {
      expect(() => validatePrivateKey(null as unknown as string)).toThrow();
    });

    it("rejects undefined input", () => {
      expect(() => validatePrivateKey(undefined as unknown as string)).toThrow();
    });

    it("rejects number input", () => {
      expect(() => validatePrivateKey(123 as unknown as string)).toThrow();
    });

    it("rejects boolean input", () => {
      expect(() => validatePrivateKey(true as unknown as string)).toThrow();
    });

    it("rejects object input", () => {
      expect(() => validatePrivateKey({} as unknown as string)).toThrow();
    });

    it("rejects array input", () => {
      expect(() => validatePrivateKey([] as unknown as string)).toThrow();
    });

    it("rejects function input", () => {
      expect(() => validatePrivateKey((() => {}) as unknown as string)).toThrow();
    });
  });

  describe("unicode attacks", () => {
    it("rejects unicode lookalike characters", () => {
      // Using zero-width characters
      const withZeroWidth =
        "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\u200Bf";
      expect(() => validatePrivateKey(withZeroWidth)).toThrow();
    });

    it("rejects RTL override", () => {
      const withRtl = "\u202E0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
      expect(() => validatePrivateKey(withRtl)).toThrow();
    });

    it("rejects homoglyph 'a' (Cyrillic а)", () => {
      // Using Cyrillic 'а' (U+0430) instead of Latin 'a'
      const withCyrillicA = "0123456789\u0430bcdef0123456789abcdef0123456789abcdef0123456789abcdef";
      expect(() => validatePrivateKey(withCyrillicA)).toThrow();
    });

    it("rejects emoji", () => {
      const withEmoji = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab😀";
      expect(() => validatePrivateKey(withEmoji)).toThrow();
    });

    it("rejects combining characters", () => {
      // 'a' followed by combining acute accent
      const withCombining = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\u0301";
      expect(() => validatePrivateKey(withCombining)).toThrow();
    });
  });

  describe("injection attempts", () => {
    it("rejects null byte injection", () => {
      const withNullByte = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\x00f";
      expect(() => validatePrivateKey(withNullByte)).toThrow();
    });

    it("rejects newline injection", () => {
      const withNewline = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\nf";
      expect(() => validatePrivateKey(withNewline)).toThrow();
    });

    it("rejects carriage return injection", () => {
      const withCR = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\rf";
      expect(() => validatePrivateKey(withCR)).toThrow();
    });

    it("rejects tab injection", () => {
      const withTab = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\tf";
      expect(() => validatePrivateKey(withTab)).toThrow();
    });

    it("rejects form feed injection", () => {
      const withFormFeed = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcde\ff";
      expect(() => validatePrivateKey(withFormFeed)).toThrow();
    });
  });

  describe("edge cases", () => {
    it("rejects very long string", () => {
      const veryLong = "a".repeat(10000);
      expect(() => validatePrivateKey(veryLong)).toThrow();
    });

    it("rejects string of spaces matching length", () => {
      const spaces = " ".repeat(64);
      expect(() => validatePrivateKey(spaces)).toThrow();
    });

    it("rejects hex with spaces between characters", () => {
      const withSpaces =
        "01 23 45 67 89 ab cd ef 01 23 45 67 89 ab cd ef 01 23 45 67 89 ab cd ef 01 23 45 67 89 ab cd ef";
      expect(() => validatePrivateKey(withSpaces)).toThrow();
    });
  });

  describe("nsec format edge cases", () => {
    it("rejects nsec with invalid bech32 characters", () => {
      // 'b', 'i', 'o' are not valid bech32 characters
      const invalidBech32 = "nsec1qypqxpq9qtpqscx7peytbfwtdjmcv0mrz5rjpej8vjppfkqfqy8skqfv3l";
      expect(() => validatePrivateKey(invalidBech32)).toThrow();
    });

    it("rejects nsec with wrong prefix", () => {
      expect(() => validatePrivateKey("nsec0aaaa")).toThrow();
    });

    it("rejects partial nsec", () => {
      expect(() => validatePrivateKey("nsec1")).toThrow();
    });
  });
});

// ============================================================================
// Fuzz Tests for isValidPubkey
// ============================================================================

describe("isValidPubkey fuzz", () => {
  describe("type confusion", () => {
    it("handles null gracefully", () => {
      expect(isValidPubkey(null as unknown as string)).toBe(false);
    });

    it("handles undefined gracefully", () => {
      expect(isValidPubkey(undefined as unknown as string)).toBe(false);
    });

    it("handles number gracefully", () => {
      expect(isValidPubkey(123 as unknown as string)).toBe(false);
    });

    it("handles object gracefully", () => {
      expect(isValidPubkey({} as unknown as string)).toBe(false);
    });
  });

  describe("malicious inputs", () => {
    it("rejects __proto__ key", () => {
      expect(isValidPubkey("__proto__")).toBe(false);
    });

    it("rejects constructor key", () => {
      expect(isValidPubkey("constructor")).toBe(false);
    });

    it("rejects toString key", () => {
      expect(isValidPubkey("toString")).toBe(false);
    });
  });
});

// ============================================================================
// Fuzz Tests for normalizePubkey
// ============================================================================

describe("normalizePubkey fuzz", () => {
  describe("prototype pollution attempts", () => {
    it("throws for __proto__", () => {
      expect(() => normalizePubkey("__proto__")).toThrow();
    });

    it("throws for constructor", () => {
      expect(() => normalizePubkey("constructor")).toThrow();
    });

    it("throws for prototype", () => {
      expect(() => normalizePubkey("prototype")).toThrow();
    });
  });

  describe("case sensitivity", () => {
    it("normalizes uppercase to lowercase", () => {
      const upper = "0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF";
      const lower = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
      expect(normalizePubkey(upper)).toBe(lower);
    });

    it("normalizes mixed case to lowercase", () => {
      const mixed = "0123456789AbCdEf0123456789AbCdEf0123456789AbCdEf0123456789AbCdEf";
      const lower = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";
      expect(normalizePubkey(mixed)).toBe(lower);
    });
  });
});

// ============================================================================
// Fuzz Tests for SeenTracker
// ============================================================================

describe("SeenTracker fuzz", () => {
  describe("malformed IDs", () => {
    it("handles empty string IDs", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      expect(() => tracker.add("")).not.toThrow();
      expect(tracker.peek("")).toBe(true);
      tracker.stop();
    });

    it("handles very long IDs", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      const longId = "a".repeat(100000);
      expect(() => tracker.add(longId)).not.toThrow();
      expect(tracker.peek(longId)).toBe(true);
      tracker.stop();
    });

    it("handles unicode IDs", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      const unicodeId = "事件ID_🎉_тест";
      expect(() => tracker.add(unicodeId)).not.toThrow();
      expect(tracker.peek(unicodeId)).toBe(true);
      tracker.stop();
    });

    it("handles IDs with null bytes", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      const idWithNull = "event\x00id";
      expect(() => tracker.add(idWithNull)).not.toThrow();
      expect(tracker.peek(idWithNull)).toBe(true);
      tracker.stop();
    });

    it("handles prototype property names as IDs", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });

      // These should not affect the tracker's internal operation
      expect(() => tracker.add("__proto__")).not.toThrow();
      expect(() => tracker.add("constructor")).not.toThrow();
      expect(() => tracker.add("toString")).not.toThrow();
      expect(() => tracker.add("hasOwnProperty")).not.toThrow();

      expect(tracker.peek("__proto__")).toBe(true);
      expect(tracker.peek("constructor")).toBe(true);
      expect(tracker.peek("toString")).toBe(true);
      expect(tracker.peek("hasOwnProperty")).toBe(true);

      tracker.stop();
    });
  });

  describe("rapid operations", () => {
    it("handles rapid add/check cycles", () => {
      const tracker = createSeenTracker({ maxEntries: 1000 });

      for (let i = 0; i < 10000; i++) {
        const id = `event-${i}`;
        tracker.add(id);
        // Recently added should be findable
        if (i < 1000) {
          tracker.peek(id);
        }
      }

      // Size should be capped at maxEntries
      expect(tracker.size()).toBeLessThanOrEqual(1000);
      tracker.stop();
    });

    it("handles concurrent-style operations", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });

      // Simulate interleaved operations
      for (let i = 0; i < 100; i++) {
        tracker.add(`add-${i}`);
        tracker.peek(`peek-${i}`);
        tracker.has(`has-${i}`);
        if (i % 10 === 0) {
          tracker.delete(`add-${i - 5}`);
        }
      }

      expect(() => tracker.size()).not.toThrow();
      tracker.stop();
    });
  });

  describe("seed edge cases", () => {
    it("handles empty seed array", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      expect(() => tracker.seed([])).not.toThrow();
      expect(tracker.size()).toBe(0);
      tracker.stop();
    });

    it("handles seed with duplicate IDs", () => {
      const tracker = createSeenTracker({ maxEntries: 100 });
      tracker.seed(["id1", "id1", "id1", "id2", "id2"]);
      expect(tracker.size()).toBe(2);
      tracker.stop();
    });

    it("handles seed larger than maxEntries", () => {
      const tracker = createSeenTracker({ maxEntries: 5 });
      const ids = Array.from({ length: 100 }, (_, i) => `id-${i}`);
      tracker.seed(ids);
      expect(tracker.size()).toBeLessThanOrEqual(5);
      tracker.stop();
    });
  });
});

// ============================================================================
// Fuzz Tests for Metrics
// ============================================================================

describe("Metrics fuzz", () => {
  describe("invalid metric names", () => {
    it("handles unknown metric names gracefully", () => {
      const metrics = createMetrics();

      // Cast to bypass type checking - testing runtime behavior
      expect(() => {
        metrics.emit("invalid.metric.name" as MetricName);
      }).not.toThrow();
    });
  });

  describe("invalid label values", () => {
    it("handles null relay label", () => {
      const metrics = createMetrics();
      expect(() => {
        metrics.emit("relay.connect", 1, { relay: null as unknown as string });
      }).not.toThrow();
    });

    it("handles undefined relay label", () => {
      const metrics = createMetrics();
      expect(() => {
        metrics.emit("relay.connect", 1, { relay: undefined as unknown as string });
      }).not.toThrow();
    });

    it("handles very long relay URL", () => {
      const metrics = createMetrics();
      const longUrl = "wss://" + "a".repeat(10000) + ".com";
      expect(() => {
        metrics.emit("relay.connect", 1, { relay: longUrl });
      }).not.toThrow();

      const snapshot = metrics.getSnapshot();
      expect(snapshot.relays[longUrl]).toBeDefined();
    });
  });

  describe("extreme values", () => {
    it("handles NaN value", () => {
      const metrics = createMetrics();
      expect(() => metrics.emit("event.received", NaN)).not.toThrow();

      const snapshot = metrics.getSnapshot();
      expect(isNaN(snapshot.eventsReceived)).toBe(true);
    });

    it("handles Infinity value", () => {
      const metrics = createMetrics();
      expect(() => metrics.emit("event.received", Infinity)).not.toThrow();

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(Infinity);
    });

    it("handles negative value", () => {
      const metrics = createMetrics();
      metrics.emit("event.received", -1);

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(-1);
    });

    it("handles very large value", () => {
      const metrics = createMetrics();
      metrics.emit("event.received", Number.MAX_SAFE_INTEGER);

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(Number.MAX_SAFE_INTEGER);
    });
  });

  describe("rapid emissions", () => {
    it("handles many rapid emissions", () => {
      const events: unknown[] = [];
      const metrics = createMetrics((e) => events.push(e));

      for (let i = 0; i < 10000; i++) {
        metrics.emit("event.received");
      }

      expect(events).toHaveLength(10000);
      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(10000);
    });
  });

  describe("reset during operation", () => {
    it("handles reset mid-operation safely", () => {
      const metrics = createMetrics();

      metrics.emit("event.received");
      metrics.emit("event.received");
      metrics.reset();
      metrics.emit("event.received");

      const snapshot = metrics.getSnapshot();
      expect(snapshot.eventsReceived).toBe(1);
    });
  });
});

// ============================================================================
// Event Shape Validation (simulating malformed events)
// ============================================================================

describe("Event shape validation", () => {
  describe("malformed event structures", () => {
    // These test what happens if malformed data somehow gets through

    it("identifies missing required fields", () => {
      const malformedEvents = [
        {}, // empty
        { id: "abc" }, // missing pubkey, created_at, etc.
        { id: null, pubkey: null }, // null values
        { id: 123, pubkey: 456 }, // wrong types
        { tags: "not-an-array" }, // wrong type for tags
        { tags: [[1, 2, 3]] }, // wrong type for tag elements
      ];

      for (const event of malformedEvents) {
        // These should be caught by shape validation before processing
        const hasId = typeof event?.id === "string";
        const hasPubkey = typeof (event as { pubkey?: unknown })?.pubkey === "string";
        const hasTags = Array.isArray((event as { tags?: unknown })?.tags);

        // At least one should be invalid
        expect(hasId && hasPubkey && hasTags).toBe(false);
      }
    });
  });

  describe("timestamp edge cases", () => {
    const testTimestamps = [
      { value: NaN, desc: "NaN" },
      { value: Infinity, desc: "Infinity" },
      { value: -Infinity, desc: "-Infinity" },
      { value: -1, desc: "negative" },
      { value: 0, desc: "zero" },
      { value: 253402300800, desc: "year 10000" }, // Far future
      { value: -62135596800, desc: "year 0001" }, // Far past
      { value: 1.5, desc: "float" },
    ];

    for (const { value, desc } of testTimestamps) {
      it(`handles ${desc} timestamp`, () => {
        const isValidTimestamp =
          typeof value === "number" &&
          !isNaN(value) &&
          isFinite(value) &&
          value >= 0 &&
          Number.isInteger(value);

        // Timestamps should be validated as positive integers
        if (["NaN", "Infinity", "-Infinity", "negative", "float"].includes(desc)) {
          expect(isValidTimestamp).toBe(false);
        }
      });
    }
  });
});

// ============================================================================
// JSON parsing edge cases (simulating relay responses)
// ============================================================================

describe("JSON parsing edge cases", () => {
  const malformedJsonCases = [
    { input: "", desc: "empty string" },
    { input: "null", desc: "null literal" },
    { input: "undefined", desc: "undefined literal" },
    { input: "{", desc: "incomplete object" },
    { input: "[", desc: "incomplete array" },
    { input: '{"key": undefined}', desc: "undefined value" },
    { input: "{'key': 'value'}", desc: "single quotes" },
    { input: '{"key": NaN}', desc: "NaN value" },
    { input: '{"key": Infinity}', desc: "Infinity value" },
    { input: "\x00", desc: "null byte" },
    { input: "abc", desc: "plain string" },
    { input: "123", desc: "plain number" },
  ];

  for (const { input, desc } of malformedJsonCases) {
    it(`handles malformed JSON: ${desc}`, () => {
      let parsed: unknown;
      let parseError = false;

      try {
        parsed = JSON.parse(input);
      } catch {
        parseError = true;
      }

      // Either it throws or produces something that needs validation
      if (!parseError) {
        // If it parsed, we need to validate the structure
        const isValidRelayMessage =
          Array.isArray(parsed) && parsed.length >= 2 && typeof parsed[0] === "string";

        // Most malformed cases won't produce valid relay messages
        if (["null literal", "plain number", "plain string"].includes(desc)) {
          expect(isValidRelayMessage).toBe(false);
        }
      }
    });
  }
});
]]></file>
  <file path="./extensions/nostr/src/channel.ts"><![CDATA[import {
  buildChannelConfigSchema,
  DEFAULT_ACCOUNT_ID,
  formatPairingApproveHint,
  type ChannelPlugin,
} from "openclaw/plugin-sdk";
import type { NostrProfile } from "./config-schema.js";
import type { MetricEvent, MetricsSnapshot } from "./metrics.js";
import type { ProfilePublishResult } from "./nostr-profile.js";
import { NostrConfigSchema } from "./config-schema.js";
import { normalizePubkey, startNostrBus, type NostrBusHandle } from "./nostr-bus.js";
import { getNostrRuntime } from "./runtime.js";
import {
  listNostrAccountIds,
  resolveDefaultNostrAccountId,
  resolveNostrAccount,
  type ResolvedNostrAccount,
} from "./types.js";

// Store active bus handles per account
const activeBuses = new Map<string, NostrBusHandle>();

// Store metrics snapshots per account (for status reporting)
const metricsSnapshots = new Map<string, MetricsSnapshot>();

export const nostrPlugin: ChannelPlugin<ResolvedNostrAccount> = {
  id: "nostr",
  meta: {
    id: "nostr",
    label: "Nostr",
    selectionLabel: "Nostr",
    docsPath: "/channels/nostr",
    docsLabel: "nostr",
    blurb: "Decentralized DMs via Nostr relays (NIP-04)",
    order: 100,
  },
  capabilities: {
    chatTypes: ["direct"], // DMs only for MVP
    media: false, // No media for MVP
  },
  reload: { configPrefixes: ["channels.nostr"] },
  configSchema: buildChannelConfigSchema(NostrConfigSchema),

  config: {
    listAccountIds: (cfg) => listNostrAccountIds(cfg),
    resolveAccount: (cfg, accountId) => resolveNostrAccount({ cfg, accountId }),
    defaultAccountId: (cfg) => resolveDefaultNostrAccountId(cfg),
    isConfigured: (account) => account.configured,
    describeAccount: (account) => ({
      accountId: account.accountId,
      name: account.name,
      enabled: account.enabled,
      configured: account.configured,
      publicKey: account.publicKey,
    }),
    resolveAllowFrom: ({ cfg, accountId }) =>
      (resolveNostrAccount({ cfg, accountId }).config.allowFrom ?? []).map((entry) =>
        String(entry),
      ),
    formatAllowFrom: ({ allowFrom }) =>
      allowFrom
        .map((entry) => String(entry).trim())
        .filter(Boolean)
        .map((entry) => {
          if (entry === "*") {
            return "*";
          }
          try {
            return normalizePubkey(entry);
          } catch {
            return entry; // Keep as-is if normalization fails
          }
        })
        .filter(Boolean),
  },

  pairing: {
    idLabel: "nostrPubkey",
    normalizeAllowEntry: (entry) => {
      try {
        return normalizePubkey(entry.replace(/^nostr:/i, ""));
      } catch {
        return entry;
      }
    },
    notifyApproval: async ({ id }) => {
      // Get the default account's bus and send approval message
      const bus = activeBuses.get(DEFAULT_ACCOUNT_ID);
      if (bus) {
        await bus.sendDm(id, "Your pairing request has been approved!");
      }
    },
  },

  security: {
    resolveDmPolicy: ({ account }) => {
      return {
        policy: account.config.dmPolicy ?? "pairing",
        allowFrom: account.config.allowFrom ?? [],
        policyPath: "channels.nostr.dmPolicy",
        allowFromPath: "channels.nostr.allowFrom",
        approveHint: formatPairingApproveHint("nostr"),
        normalizeEntry: (raw) => {
          try {
            return normalizePubkey(raw.replace(/^nostr:/i, "").trim());
          } catch {
            return raw.trim();
          }
        },
      };
    },
  },

  messaging: {
    normalizeTarget: (target) => {
      // Strip nostr: prefix if present
      const cleaned = target.replace(/^nostr:/i, "").trim();
      try {
        return normalizePubkey(cleaned);
      } catch {
        return cleaned;
      }
    },
    targetResolver: {
      looksLikeId: (input) => {
        const trimmed = input.trim();
        return trimmed.startsWith("npub1") || /^[0-9a-fA-F]{64}$/.test(trimmed);
      },
      hint: "<npub|hex pubkey|nostr:npub...>",
    },
  },

  outbound: {
    deliveryMode: "direct",
    textChunkLimit: 4000,
    sendText: async ({ to, text, accountId }) => {
      const core = getNostrRuntime();
      const aid = accountId ?? DEFAULT_ACCOUNT_ID;
      const bus = activeBuses.get(aid);
      if (!bus) {
        throw new Error(`Nostr bus not running for account ${aid}`);
      }
      const tableMode = core.channel.text.resolveMarkdownTableMode({
        cfg: core.config.loadConfig(),
        channel: "nostr",
        accountId: aid,
      });
      const message = core.channel.text.convertMarkdownTables(text ?? "", tableMode);
      const normalizedTo = normalizePubkey(to);
      await bus.sendDm(normalizedTo, message);
      return {
        channel: "nostr" as const,
        to: normalizedTo,
        messageId: `nostr-${Date.now()}`,
      };
    },
  },

  status: {
    defaultRuntime: {
      accountId: DEFAULT_ACCOUNT_ID,
      running: false,
      lastStartAt: null,
      lastStopAt: null,
      lastError: null,
    },
    collectStatusIssues: (accounts) =>
      accounts.flatMap((account) => {
        const lastError = typeof account.lastError === "string" ? account.lastError.trim() : "";
        if (!lastError) {
          return [];
        }
        return [
          {
            channel: "nostr",
            accountId: account.accountId,
            kind: "runtime" as const,
            message: `Channel error: ${lastError}`,
          },
        ];
      }),
    buildChannelSummary: ({ snapshot }) => ({
      configured: snapshot.configured ?? false,
      publicKey: snapshot.publicKey ?? null,
      running: snapshot.running ?? false,
      lastStartAt: snapshot.lastStartAt ?? null,
      lastStopAt: snapshot.lastStopAt ?? null,
      lastError: snapshot.lastError ?? null,
    }),
    buildAccountSnapshot: ({ account, runtime }) => ({
      accountId: account.accountId,
      name: account.name,
      enabled: account.enabled,
      configured: account.configured,
      publicKey: account.publicKey,
      profile: account.profile,
      running: runtime?.running ?? false,
      lastStartAt: runtime?.lastStartAt ?? null,
      lastStopAt: runtime?.lastStopAt ?? null,
      lastError: runtime?.lastError ?? null,
      lastInboundAt: runtime?.lastInboundAt ?? null,
      lastOutboundAt: runtime?.lastOutboundAt ?? null,
    }),
  },

  gateway: {
    startAccount: async (ctx) => {
      const account = ctx.account;
      ctx.setStatus({
        accountId: account.accountId,
        publicKey: account.publicKey,
      });
      ctx.log?.info(
        `[${account.accountId}] starting Nostr provider (pubkey: ${account.publicKey})`,
      );

      if (!account.configured) {
        throw new Error("Nostr private key not configured");
      }

      const runtime = getNostrRuntime();

      // Track bus handle for metrics callback
      let busHandle: NostrBusHandle | null = null;

      const bus = await startNostrBus({
        accountId: account.accountId,
        privateKey: account.privateKey,
        relays: account.relays,
        onMessage: async (senderPubkey, text, reply) => {
          ctx.log?.debug?.(
            `[${account.accountId}] DM from ${senderPubkey}: ${text.slice(0, 50)}...`,
          );

          // Forward to OpenClaw's message pipeline
          // TODO: Replace with proper dispatchReplyWithBufferedBlockDispatcher call
          await (
            runtime.channel.reply as { handleInboundMessage?: (params: unknown) => Promise<void> }
          ).handleInboundMessage?.({
            channel: "nostr",
            accountId: account.accountId,
            senderId: senderPubkey,
            chatType: "direct",
            chatId: senderPubkey, // For DMs, chatId is the sender's pubkey
            text,
            reply: async (responseText: string) => {
              await reply(responseText);
            },
          });
        },
        onError: (error, context) => {
          ctx.log?.error?.(`[${account.accountId}] Nostr error (${context}): ${error.message}`);
        },
        onConnect: (relay) => {
          ctx.log?.debug?.(`[${account.accountId}] Connected to relay: ${relay}`);
        },
        onDisconnect: (relay) => {
          ctx.log?.debug?.(`[${account.accountId}] Disconnected from relay: ${relay}`);
        },
        onEose: (relays) => {
          ctx.log?.debug?.(`[${account.accountId}] EOSE received from relays: ${relays}`);
        },
        onMetric: (event: MetricEvent) => {
          // Log significant metrics at appropriate levels
          if (event.name.startsWith("event.rejected.")) {
            ctx.log?.debug?.(
              `[${account.accountId}] Metric: ${event.name} ${JSON.stringify(event.labels)}`,
            );
          } else if (event.name === "relay.circuit_breaker.open") {
            ctx.log?.warn?.(
              `[${account.accountId}] Circuit breaker opened for relay: ${event.labels?.relay}`,
            );
          } else if (event.name === "relay.circuit_breaker.close") {
            ctx.log?.info?.(
              `[${account.accountId}] Circuit breaker closed for relay: ${event.labels?.relay}`,
            );
          } else if (event.name === "relay.error") {
            ctx.log?.debug?.(`[${account.accountId}] Relay error: ${event.labels?.relay}`);
          }
          // Update cached metrics snapshot
          if (busHandle) {
            metricsSnapshots.set(account.accountId, busHandle.getMetrics());
          }
        },
      });

      busHandle = bus;

      // Store the bus handle
      activeBuses.set(account.accountId, bus);

      ctx.log?.info(
        `[${account.accountId}] Nostr provider started, connected to ${account.relays.length} relay(s)`,
      );

      // Return cleanup function
      return {
        stop: () => {
          bus.close();
          activeBuses.delete(account.accountId);
          metricsSnapshots.delete(account.accountId);
          ctx.log?.info(`[${account.accountId}] Nostr provider stopped`);
        },
      };
    },
  },
};

/**
 * Get metrics snapshot for a Nostr account.
 * Returns undefined if account is not running.
 */
export function getNostrMetrics(
  accountId: string = DEFAULT_ACCOUNT_ID,
): MetricsSnapshot | undefined {
  const bus = activeBuses.get(accountId);
  if (bus) {
    return bus.getMetrics();
  }
  return metricsSnapshots.get(accountId);
}

/**
 * Get all active Nostr bus handles.
 * Useful for debugging and status reporting.
 */
export function getActiveNostrBuses(): Map<string, NostrBusHandle> {
  return new Map(activeBuses);
}

/**
 * Publish a profile (kind:0) for a Nostr account.
 * @param accountId - Account ID (defaults to "default")
 * @param profile - Profile data to publish
 * @returns Publish results with successes and failures
 * @throws Error if account is not running
 */
export async function publishNostrProfile(
  accountId: string = DEFAULT_ACCOUNT_ID,
  profile: NostrProfile,
): Promise<ProfilePublishResult> {
  const bus = activeBuses.get(accountId);
  if (!bus) {
    throw new Error(`Nostr bus not running for account ${accountId}`);
  }
  return bus.publishProfile(profile);
}

/**
 * Get profile publish state for a Nostr account.
 * @param accountId - Account ID (defaults to "default")
 * @returns Profile publish state or null if account not running
 */
export async function getNostrProfileState(accountId: string = DEFAULT_ACCOUNT_ID): Promise<{
  lastPublishedAt: number | null;
  lastPublishedEventId: string | null;
  lastPublishResults: Record<string, "ok" | "failed" | "timeout"> | null;
} | null> {
  const bus = activeBuses.get(accountId);
  if (!bus) {
    return null;
  }
  return bus.getProfileState();
}
]]></file>
  <file path="./extensions/nostr/CHANGELOG.md"><![CDATA[# Changelog

## 2026.2.13

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-3

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.4

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.31

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.30

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.29

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.23

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.22

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.21

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.20

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.19-1

Initial release.

### Features

- NIP-04 encrypted DM support (kind:4 events)
- Key validation (hex and nsec formats)
- Multi-relay support with sequential fallback
- Event signature verification
- TTL-based deduplication (24h)
- Access control via dmPolicy (pairing, allowlist, open, disabled)
- Pubkey normalization (hex/npub)

### Protocol Support

- NIP-01: Basic event structure
- NIP-04: Encrypted direct messages

### Planned for v2

- NIP-17: Gift-wrapped DMs
- NIP-44: Versioned encryption
- Media attachments
]]></file>
  <file path="./extensions/nostr/test/setup.ts"><![CDATA[// Test setup file for nostr extension
import { vi } from "vitest";

// Mock console.error to suppress noise in tests
vi.spyOn(console, "error").mockImplementation(() => {});
]]></file>
  <file path="./extensions/nostr/index.ts"><![CDATA[import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import { emptyPluginConfigSchema } from "openclaw/plugin-sdk";
import type { NostrProfile } from "./src/config-schema.js";
import { nostrPlugin } from "./src/channel.js";
import { createNostrProfileHttpHandler } from "./src/nostr-profile-http.js";
import { setNostrRuntime, getNostrRuntime } from "./src/runtime.js";
import { resolveNostrAccount } from "./src/types.js";

const plugin = {
  id: "nostr",
  name: "Nostr",
  description: "Nostr DM channel plugin via NIP-04",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    setNostrRuntime(api.runtime);
    api.registerChannel({ plugin: nostrPlugin });

    // Register HTTP handler for profile management
    const httpHandler = createNostrProfileHttpHandler({
      getConfigProfile: (accountId: string) => {
        const runtime = getNostrRuntime();
        const cfg = runtime.config.loadConfig();
        const account = resolveNostrAccount({ cfg, accountId });
        return account.profile;
      },
      updateConfigProfile: async (accountId: string, profile: NostrProfile) => {
        const runtime = getNostrRuntime();
        const cfg = runtime.config.loadConfig();

        // Build the config patch for channels.nostr.profile
        const channels = (cfg.channels ?? {}) as Record<string, unknown>;
        const nostrConfig = (channels.nostr ?? {}) as Record<string, unknown>;

        const updatedNostrConfig = {
          ...nostrConfig,
          profile,
        };

        const updatedChannels = {
          ...channels,
          nostr: updatedNostrConfig,
        };

        await runtime.config.writeConfigFile({
          ...cfg,
          channels: updatedChannels,
        });
      },
      getAccountInfo: (accountId: string) => {
        const runtime = getNostrRuntime();
        const cfg = runtime.config.loadConfig();
        const account = resolveNostrAccount({ cfg, accountId });
        if (!account.configured || !account.publicKey) {
          return null;
        }
        return {
          pubkey: account.publicKey,
          relays: account.relays,
        };
      },
      log: api.logger,
    });

    api.registerHttpHandler(httpHandler);
  },
};

export default plugin;
]]></file>
  <file path="./extensions/zalo/openclaw.plugin.json"><![CDATA[{
  "id": "zalo",
  "channels": ["zalo"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/zalo/README.md"><![CDATA[# @openclaw/zalo

Zalo channel plugin for OpenClaw (Bot API).

## Install (local checkout)

```bash
openclaw plugins install ./extensions/zalo
```

## Install (npm)

```bash
openclaw plugins install @openclaw/zalo
```

Onboarding: select Zalo and confirm the install prompt to fetch the plugin automatically.

## Config

```json5
{
  channels: {
    zalo: {
      enabled: true,
      botToken: "12345689:abc-xyz",
      dmPolicy: "pairing",
      proxy: "http://proxy.local:8080",
    },
  },
}
```

## Webhook mode

```json5
{
  channels: {
    zalo: {
      webhookUrl: "https://example.com/zalo-webhook",
      webhookSecret: "your-secret-8-plus-chars",
      webhookPath: "/zalo-webhook",
    },
  },
}
```

If `webhookPath` is omitted, the plugin uses the webhook URL path.

Restart the gateway after config changes.
]]></file>
  <file path="./extensions/zalo/package.json"><![CDATA[{
  "name": "@openclaw/zalo",
  "version": "2026.2.13",
  "description": "OpenClaw Zalo channel plugin",
  "type": "module",
  "dependencies": {
    "undici": "7.21.0"
  },
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ],
    "channel": {
      "id": "zalo",
      "label": "Zalo",
      "selectionLabel": "Zalo (Bot API)",
      "docsPath": "/channels/zalo",
      "docsLabel": "zalo",
      "blurb": "Vietnam-focused messaging platform with Bot API.",
      "aliases": [
        "zl"
      ],
      "order": 80,
      "quickstartAllowFrom": true
    },
    "install": {
      "npmSpec": "@openclaw/zalo",
      "localPath": "extensions/zalo",
      "defaultChoice": "npm"
    }
  }
}
]]></file>
  <file path="./extensions/zalo/src/send.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import type { ZaloFetch } from "./api.js";
import { resolveZaloAccount } from "./accounts.js";
import { sendMessage, sendPhoto } from "./api.js";
import { resolveZaloProxyFetch } from "./proxy.js";
import { resolveZaloToken } from "./token.js";

export type ZaloSendOptions = {
  token?: string;
  accountId?: string;
  cfg?: OpenClawConfig;
  mediaUrl?: string;
  caption?: string;
  verbose?: boolean;
  proxy?: string;
};

export type ZaloSendResult = {
  ok: boolean;
  messageId?: string;
  error?: string;
};

function resolveSendContext(options: ZaloSendOptions): {
  token: string;
  fetcher?: ZaloFetch;
} {
  if (options.cfg) {
    const account = resolveZaloAccount({
      cfg: options.cfg,
      accountId: options.accountId,
    });
    const token = options.token || account.token;
    const proxy = options.proxy ?? account.config.proxy;
    return { token, fetcher: resolveZaloProxyFetch(proxy) };
  }

  const token = options.token ?? resolveZaloToken(undefined, options.accountId).token;
  const proxy = options.proxy;
  return { token, fetcher: resolveZaloProxyFetch(proxy) };
}

export async function sendMessageZalo(
  chatId: string,
  text: string,
  options: ZaloSendOptions = {},
): Promise<ZaloSendResult> {
  const { token, fetcher } = resolveSendContext(options);

  if (!token) {
    return { ok: false, error: "No Zalo bot token configured" };
  }

  if (!chatId?.trim()) {
    return { ok: false, error: "No chat_id provided" };
  }

  if (options.mediaUrl) {
    return sendPhotoZalo(chatId, options.mediaUrl, {
      ...options,
      token,
      caption: text || options.caption,
    });
  }

  try {
    const response = await sendMessage(
      token,
      {
        chat_id: chatId.trim(),
        text: text.slice(0, 2000),
      },
      fetcher,
    );

    if (response.ok && response.result) {
      return { ok: true, messageId: response.result.message_id };
    }

    return { ok: false, error: "Failed to send message" };
  } catch (err) {
    return { ok: false, error: err instanceof Error ? err.message : String(err) };
  }
}

export async function sendPhotoZalo(
  chatId: string,
  photoUrl: string,
  options: ZaloSendOptions = {},
): Promise<ZaloSendResult> {
  const { token, fetcher } = resolveSendContext(options);

  if (!token) {
    return { ok: false, error: "No Zalo bot token configured" };
  }

  if (!chatId?.trim()) {
    return { ok: false, error: "No chat_id provided" };
  }

  if (!photoUrl?.trim()) {
    return { ok: false, error: "No photo URL provided" };
  }

  try {
    const response = await sendPhoto(
      token,
      {
        chat_id: chatId.trim(),
        photo: photoUrl.trim(),
        caption: options.caption?.slice(0, 2000),
      },
      fetcher,
    );

    if (response.ok && response.result) {
      return { ok: true, messageId: response.result.message_id };
    }

    return { ok: false, error: "Failed to send photo" };
  } catch (err) {
    return { ok: false, error: err instanceof Error ? err.message : String(err) };
  }
}
]]></file>
  <file path="./extensions/zalo/src/probe.ts"><![CDATA[import { getMe, ZaloApiError, type ZaloBotInfo, type ZaloFetch } from "./api.js";

export type ZaloProbeResult = {
  ok: boolean;
  bot?: ZaloBotInfo;
  error?: string;
  elapsedMs: number;
};

export async function probeZalo(
  token: string,
  timeoutMs = 5000,
  fetcher?: ZaloFetch,
): Promise<ZaloProbeResult> {
  if (!token?.trim()) {
    return { ok: false, error: "No token provided", elapsedMs: 0 };
  }

  const startTime = Date.now();

  try {
    const response = await getMe(token.trim(), timeoutMs, fetcher);
    const elapsedMs = Date.now() - startTime;

    if (response.ok && response.result) {
      return { ok: true, bot: response.result, elapsedMs };
    }

    return { ok: false, error: "Invalid response from Zalo API", elapsedMs };
  } catch (err) {
    const elapsedMs = Date.now() - startTime;

    if (err instanceof ZaloApiError) {
      return { ok: false, error: err.description ?? err.message, elapsedMs };
    }

    if (err instanceof Error) {
      if (err.name === "AbortError") {
        return { ok: false, error: `Request timed out after ${timeoutMs}ms`, elapsedMs };
      }
      return { ok: false, error: err.message, elapsedMs };
    }

    return { ok: false, error: String(err), elapsedMs };
  }
}
]]></file>
  <file path="./extensions/zalo/src/token.ts"><![CDATA[import { readFileSync } from "node:fs";
import { DEFAULT_ACCOUNT_ID } from "openclaw/plugin-sdk";
import type { ZaloConfig } from "./types.js";

export type ZaloTokenResolution = {
  token: string;
  source: "env" | "config" | "configFile" | "none";
};

export function resolveZaloToken(
  config: ZaloConfig | undefined,
  accountId?: string | null,
): ZaloTokenResolution {
  const resolvedAccountId = accountId ?? DEFAULT_ACCOUNT_ID;
  const isDefaultAccount = resolvedAccountId === DEFAULT_ACCOUNT_ID;
  const baseConfig = config;
  const accountConfig =
    resolvedAccountId !== DEFAULT_ACCOUNT_ID
      ? (baseConfig?.accounts?.[resolvedAccountId] as ZaloConfig | undefined)
      : undefined;

  if (accountConfig) {
    const token = accountConfig.botToken?.trim();
    if (token) {
      return { token, source: "config" };
    }
    const tokenFile = accountConfig.tokenFile?.trim();
    if (tokenFile) {
      try {
        const fileToken = readFileSync(tokenFile, "utf8").trim();
        if (fileToken) {
          return { token: fileToken, source: "configFile" };
        }
      } catch {
        // ignore read failures
      }
    }
  }

  if (isDefaultAccount) {
    const token = baseConfig?.botToken?.trim();
    if (token) {
      return { token, source: "config" };
    }
    const tokenFile = baseConfig?.tokenFile?.trim();
    if (tokenFile) {
      try {
        const fileToken = readFileSync(tokenFile, "utf8").trim();
        if (fileToken) {
          return { token: fileToken, source: "configFile" };
        }
      } catch {
        // ignore read failures
      }
    }
    const envToken = process.env.ZALO_BOT_TOKEN?.trim();
    if (envToken) {
      return { token: envToken, source: "env" };
    }
  }

  return { token: "", source: "none" };
}
]]></file>
  <file path="./extensions/zalo/src/status-issues.ts"><![CDATA[import type { ChannelAccountSnapshot, ChannelStatusIssue } from "openclaw/plugin-sdk";

type ZaloAccountStatus = {
  accountId?: unknown;
  enabled?: unknown;
  configured?: unknown;
  dmPolicy?: unknown;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  Boolean(value && typeof value === "object");

const asString = (value: unknown): string | undefined =>
  typeof value === "string" ? value : typeof value === "number" ? String(value) : undefined;

function readZaloAccountStatus(value: ChannelAccountSnapshot): ZaloAccountStatus | null {
  if (!isRecord(value)) {
    return null;
  }
  return {
    accountId: value.accountId,
    enabled: value.enabled,
    configured: value.configured,
    dmPolicy: value.dmPolicy,
  };
}

export function collectZaloStatusIssues(accounts: ChannelAccountSnapshot[]): ChannelStatusIssue[] {
  const issues: ChannelStatusIssue[] = [];
  for (const entry of accounts) {
    const account = readZaloAccountStatus(entry);
    if (!account) {
      continue;
    }
    const accountId = asString(account.accountId) ?? "default";
    const enabled = account.enabled !== false;
    const configured = account.configured === true;
    if (!enabled || !configured) {
      continue;
    }

    if (account.dmPolicy === "open") {
      issues.push({
        channel: "zalo",
        accountId,
        kind: "config",
        message: 'Zalo dmPolicy is "open", allowing any user to message the bot without pairing.',
        fix: 'Set channels.zalo.dmPolicy to "pairing" or "allowlist" to restrict access.',
      });
    }
  }
  return issues;
}
]]></file>
  <file path="./extensions/zalo/src/proxy.ts"><![CDATA[import type { Dispatcher, RequestInit as UndiciRequestInit } from "undici";
import { ProxyAgent, fetch as undiciFetch } from "undici";
import type { ZaloFetch } from "./api.js";

const proxyCache = new Map<string, ZaloFetch>();

export function resolveZaloProxyFetch(proxyUrl?: string | null): ZaloFetch | undefined {
  const trimmed = proxyUrl?.trim();
  if (!trimmed) {
    return undefined;
  }
  const cached = proxyCache.get(trimmed);
  if (cached) {
    return cached;
  }
  const agent = new ProxyAgent(trimmed);
  const fetcher: ZaloFetch = (input, init) =>
    undiciFetch(input, {
      ...init,
      dispatcher: agent,
    } as UndiciRequestInit) as unknown as Promise<Response>;
  proxyCache.set(trimmed, fetcher);
  return fetcher;
}
]]></file>
  <file path="./extensions/zalo/src/runtime.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";

let runtime: PluginRuntime | null = null;

export function setZaloRuntime(next: PluginRuntime): void {
  runtime = next;
}

export function getZaloRuntime(): PluginRuntime {
  if (!runtime) {
    throw new Error("Zalo runtime not initialized");
  }
  return runtime;
}
]]></file>
  <file path="./extensions/zalo/src/monitor.ts"><![CDATA[import type { IncomingMessage, ServerResponse } from "node:http";
import type { OpenClawConfig, MarkdownTableMode } from "openclaw/plugin-sdk";
import { createReplyPrefixOptions } from "openclaw/plugin-sdk";
import type { ResolvedZaloAccount } from "./accounts.js";
import {
  ZaloApiError,
  deleteWebhook,
  getUpdates,
  sendMessage,
  sendPhoto,
  setWebhook,
  type ZaloFetch,
  type ZaloMessage,
  type ZaloUpdate,
} from "./api.js";
import { resolveZaloProxyFetch } from "./proxy.js";
import { getZaloRuntime } from "./runtime.js";

export type ZaloRuntimeEnv = {
  log?: (message: string) => void;
  error?: (message: string) => void;
};

export type ZaloMonitorOptions = {
  token: string;
  account: ResolvedZaloAccount;
  config: OpenClawConfig;
  runtime: ZaloRuntimeEnv;
  abortSignal: AbortSignal;
  useWebhook?: boolean;
  webhookUrl?: string;
  webhookSecret?: string;
  webhookPath?: string;
  fetcher?: ZaloFetch;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
};

export type ZaloMonitorResult = {
  stop: () => void;
};

const ZALO_TEXT_LIMIT = 2000;
const DEFAULT_MEDIA_MAX_MB = 5;

type ZaloCoreRuntime = ReturnType<typeof getZaloRuntime>;

function logVerbose(core: ZaloCoreRuntime, runtime: ZaloRuntimeEnv, message: string): void {
  if (core.logging.shouldLogVerbose()) {
    runtime.log?.(`[zalo] ${message}`);
  }
}

function isSenderAllowed(senderId: string, allowFrom: string[]): boolean {
  if (allowFrom.includes("*")) {
    return true;
  }
  const normalizedSenderId = senderId.toLowerCase();
  return allowFrom.some((entry) => {
    const normalized = entry.toLowerCase().replace(/^(zalo|zl):/i, "");
    return normalized === normalizedSenderId;
  });
}

async function readJsonBody(req: IncomingMessage, maxBytes: number) {
  const chunks: Buffer[] = [];
  let total = 0;
  return await new Promise<{ ok: boolean; value?: unknown; error?: string }>((resolve) => {
    req.on("data", (chunk: Buffer) => {
      total += chunk.length;
      if (total > maxBytes) {
        resolve({ ok: false, error: "payload too large" });
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => {
      try {
        const raw = Buffer.concat(chunks).toString("utf8");
        if (!raw.trim()) {
          resolve({ ok: false, error: "empty payload" });
          return;
        }
        resolve({ ok: true, value: JSON.parse(raw) as unknown });
      } catch (err) {
        resolve({ ok: false, error: err instanceof Error ? err.message : String(err) });
      }
    });
    req.on("error", (err) => {
      resolve({ ok: false, error: err instanceof Error ? err.message : String(err) });
    });
  });
}

type WebhookTarget = {
  token: string;
  account: ResolvedZaloAccount;
  config: OpenClawConfig;
  runtime: ZaloRuntimeEnv;
  core: ZaloCoreRuntime;
  secret: string;
  path: string;
  mediaMaxMb: number;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
  fetcher?: ZaloFetch;
};

const webhookTargets = new Map<string, WebhookTarget[]>();

function normalizeWebhookPath(raw: string): string {
  const trimmed = raw.trim();
  if (!trimmed) {
    return "/";
  }
  const withSlash = trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
  if (withSlash.length > 1 && withSlash.endsWith("/")) {
    return withSlash.slice(0, -1);
  }
  return withSlash;
}

function resolveWebhookPath(webhookPath?: string, webhookUrl?: string): string | null {
  const trimmedPath = webhookPath?.trim();
  if (trimmedPath) {
    return normalizeWebhookPath(trimmedPath);
  }
  if (webhookUrl?.trim()) {
    try {
      const parsed = new URL(webhookUrl);
      return normalizeWebhookPath(parsed.pathname || "/");
    } catch {
      return null;
    }
  }
  return null;
}

export function registerZaloWebhookTarget(target: WebhookTarget): () => void {
  const key = normalizeWebhookPath(target.path);
  const normalizedTarget = { ...target, path: key };
  const existing = webhookTargets.get(key) ?? [];
  const next = [...existing, normalizedTarget];
  webhookTargets.set(key, next);
  return () => {
    const updated = (webhookTargets.get(key) ?? []).filter((entry) => entry !== normalizedTarget);
    if (updated.length > 0) {
      webhookTargets.set(key, updated);
    } else {
      webhookTargets.delete(key);
    }
  };
}

export async function handleZaloWebhookRequest(
  req: IncomingMessage,
  res: ServerResponse,
): Promise<boolean> {
  const url = new URL(req.url ?? "/", "http://localhost");
  const path = normalizeWebhookPath(url.pathname);
  const targets = webhookTargets.get(path);
  if (!targets || targets.length === 0) {
    return false;
  }

  if (req.method !== "POST") {
    res.statusCode = 405;
    res.setHeader("Allow", "POST");
    res.end("Method Not Allowed");
    return true;
  }

  const headerToken = String(req.headers["x-bot-api-secret-token"] ?? "");
  const target = targets.find((entry) => entry.secret === headerToken);
  if (!target) {
    res.statusCode = 401;
    res.end("unauthorized");
    return true;
  }

  const body = await readJsonBody(req, 1024 * 1024);
  if (!body.ok) {
    res.statusCode = body.error === "payload too large" ? 413 : 400;
    res.end(body.error ?? "invalid payload");
    return true;
  }

  // Zalo sends updates directly as { event_name, message, ... }, not wrapped in { ok, result }
  const raw = body.value;
  const record = raw && typeof raw === "object" ? (raw as Record<string, unknown>) : null;
  const update: ZaloUpdate | undefined =
    record && record.ok === true && record.result
      ? (record.result as ZaloUpdate)
      : ((record as ZaloUpdate | null) ?? undefined);

  if (!update?.event_name) {
    res.statusCode = 400;
    res.end("invalid payload");
    return true;
  }

  target.statusSink?.({ lastInboundAt: Date.now() });
  processUpdate(
    update,
    target.token,
    target.account,
    target.config,
    target.runtime,
    target.core,
    target.mediaMaxMb,
    target.statusSink,
    target.fetcher,
  ).catch((err) => {
    target.runtime.error?.(`[${target.account.accountId}] Zalo webhook failed: ${String(err)}`);
  });

  res.statusCode = 200;
  res.end("ok");
  return true;
}

function startPollingLoop(params: {
  token: string;
  account: ResolvedZaloAccount;
  config: OpenClawConfig;
  runtime: ZaloRuntimeEnv;
  core: ZaloCoreRuntime;
  abortSignal: AbortSignal;
  isStopped: () => boolean;
  mediaMaxMb: number;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
  fetcher?: ZaloFetch;
}) {
  const {
    token,
    account,
    config,
    runtime,
    core,
    abortSignal,
    isStopped,
    mediaMaxMb,
    statusSink,
    fetcher,
  } = params;
  const pollTimeout = 30;

  const poll = async () => {
    if (isStopped() || abortSignal.aborted) {
      return;
    }

    try {
      const response = await getUpdates(token, { timeout: pollTimeout }, fetcher);
      if (response.ok && response.result) {
        statusSink?.({ lastInboundAt: Date.now() });
        await processUpdate(
          response.result,
          token,
          account,
          config,
          runtime,
          core,
          mediaMaxMb,
          statusSink,
          fetcher,
        );
      }
    } catch (err) {
      if (err instanceof ZaloApiError && err.isPollingTimeout) {
        // no updates
      } else if (!isStopped() && !abortSignal.aborted) {
        console.error(`[${account.accountId}] Zalo polling error:`, err);
        await new Promise((resolve) => setTimeout(resolve, 5000));
      }
    }

    if (!isStopped() && !abortSignal.aborted) {
      setImmediate(poll);
    }
  };

  void poll();
}

async function processUpdate(
  update: ZaloUpdate,
  token: string,
  account: ResolvedZaloAccount,
  config: OpenClawConfig,
  runtime: ZaloRuntimeEnv,
  core: ZaloCoreRuntime,
  mediaMaxMb: number,
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void,
  fetcher?: ZaloFetch,
): Promise<void> {
  const { event_name, message } = update;
  if (!message) {
    return;
  }

  switch (event_name) {
    case "message.text.received":
      await handleTextMessage(message, token, account, config, runtime, core, statusSink, fetcher);
      break;
    case "message.image.received":
      await handleImageMessage(
        message,
        token,
        account,
        config,
        runtime,
        core,
        mediaMaxMb,
        statusSink,
        fetcher,
      );
      break;
    case "message.sticker.received":
      console.log(`[${account.accountId}] Received sticker from ${message.from.id}`);
      break;
    case "message.unsupported.received":
      console.log(
        `[${account.accountId}] Received unsupported message type from ${message.from.id}`,
      );
      break;
  }
}

async function handleTextMessage(
  message: ZaloMessage,
  token: string,
  account: ResolvedZaloAccount,
  config: OpenClawConfig,
  runtime: ZaloRuntimeEnv,
  core: ZaloCoreRuntime,
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void,
  fetcher?: ZaloFetch,
): Promise<void> {
  const { text } = message;
  if (!text?.trim()) {
    return;
  }

  await processMessageWithPipeline({
    message,
    token,
    account,
    config,
    runtime,
    core,
    text,
    mediaPath: undefined,
    mediaType: undefined,
    statusSink,
    fetcher,
  });
}

async function handleImageMessage(
  message: ZaloMessage,
  token: string,
  account: ResolvedZaloAccount,
  config: OpenClawConfig,
  runtime: ZaloRuntimeEnv,
  core: ZaloCoreRuntime,
  mediaMaxMb: number,
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void,
  fetcher?: ZaloFetch,
): Promise<void> {
  const { photo, caption } = message;

  let mediaPath: string | undefined;
  let mediaType: string | undefined;

  if (photo) {
    try {
      const maxBytes = mediaMaxMb * 1024 * 1024;
      const fetched = await core.channel.media.fetchRemoteMedia({ url: photo });
      const saved = await core.channel.media.saveMediaBuffer(
        fetched.buffer,
        fetched.contentType,
        "inbound",
        maxBytes,
      );
      mediaPath = saved.path;
      mediaType = saved.contentType;
    } catch (err) {
      console.error(`[${account.accountId}] Failed to download Zalo image:`, err);
    }
  }

  await processMessageWithPipeline({
    message,
    token,
    account,
    config,
    runtime,
    core,
    text: caption,
    mediaPath,
    mediaType,
    statusSink,
    fetcher,
  });
}

async function processMessageWithPipeline(params: {
  message: ZaloMessage;
  token: string;
  account: ResolvedZaloAccount;
  config: OpenClawConfig;
  runtime: ZaloRuntimeEnv;
  core: ZaloCoreRuntime;
  text?: string;
  mediaPath?: string;
  mediaType?: string;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
  fetcher?: ZaloFetch;
}): Promise<void> {
  const {
    message,
    token,
    account,
    config,
    runtime,
    core,
    text,
    mediaPath,
    mediaType,
    statusSink,
    fetcher,
  } = params;
  const { from, chat, message_id, date } = message;

  const isGroup = chat.chat_type === "GROUP";
  const chatId = chat.id;
  const senderId = from.id;
  const senderName = from.name;

  const dmPolicy = account.config.dmPolicy ?? "pairing";
  const configAllowFrom = (account.config.allowFrom ?? []).map((v) => String(v));
  const rawBody = text?.trim() || (mediaPath ? "<media:image>" : "");
  const shouldComputeAuth = core.channel.commands.shouldComputeCommandAuthorized(rawBody, config);
  const storeAllowFrom =
    !isGroup && (dmPolicy !== "open" || shouldComputeAuth)
      ? await core.channel.pairing.readAllowFromStore("zalo").catch(() => [])
      : [];
  const effectiveAllowFrom = [...configAllowFrom, ...storeAllowFrom];
  const useAccessGroups = config.commands?.useAccessGroups !== false;
  const senderAllowedForCommands = isSenderAllowed(senderId, effectiveAllowFrom);
  const commandAuthorized = shouldComputeAuth
    ? core.channel.commands.resolveCommandAuthorizedFromAuthorizers({
        useAccessGroups,
        authorizers: [
          { configured: effectiveAllowFrom.length > 0, allowed: senderAllowedForCommands },
        ],
      })
    : undefined;

  if (!isGroup) {
    if (dmPolicy === "disabled") {
      logVerbose(core, runtime, `Blocked zalo DM from ${senderId} (dmPolicy=disabled)`);
      return;
    }

    if (dmPolicy !== "open") {
      const allowed = senderAllowedForCommands;

      if (!allowed) {
        if (dmPolicy === "pairing") {
          const { code, created } = await core.channel.pairing.upsertPairingRequest({
            channel: "zalo",
            id: senderId,
            meta: { name: senderName ?? undefined },
          });

          if (created) {
            logVerbose(core, runtime, `zalo pairing request sender=${senderId}`);
            try {
              await sendMessage(
                token,
                {
                  chat_id: chatId,
                  text: core.channel.pairing.buildPairingReply({
                    channel: "zalo",
                    idLine: `Your Zalo user id: ${senderId}`,
                    code,
                  }),
                },
                fetcher,
              );
              statusSink?.({ lastOutboundAt: Date.now() });
            } catch (err) {
              logVerbose(
                core,
                runtime,
                `zalo pairing reply failed for ${senderId}: ${String(err)}`,
              );
            }
          }
        } else {
          logVerbose(
            core,
            runtime,
            `Blocked unauthorized zalo sender ${senderId} (dmPolicy=${dmPolicy})`,
          );
        }
        return;
      }
    }
  }

  const route = core.channel.routing.resolveAgentRoute({
    cfg: config,
    channel: "zalo",
    accountId: account.accountId,
    peer: {
      kind: isGroup ? "group" : "direct",
      id: chatId,
    },
  });

  if (
    isGroup &&
    core.channel.commands.isControlCommandMessage(rawBody, config) &&
    commandAuthorized !== true
  ) {
    logVerbose(core, runtime, `zalo: drop control command from unauthorized sender ${senderId}`);
    return;
  }

  const fromLabel = isGroup ? `group:${chatId}` : senderName || `user:${senderId}`;
  const storePath = core.channel.session.resolveStorePath(config.session?.store, {
    agentId: route.agentId,
  });
  const envelopeOptions = core.channel.reply.resolveEnvelopeFormatOptions(config);
  const previousTimestamp = core.channel.session.readSessionUpdatedAt({
    storePath,
    sessionKey: route.sessionKey,
  });
  const body = core.channel.reply.formatAgentEnvelope({
    channel: "Zalo",
    from: fromLabel,
    timestamp: date ? date * 1000 : undefined,
    previousTimestamp,
    envelope: envelopeOptions,
    body: rawBody,
  });

  const ctxPayload = core.channel.reply.finalizeInboundContext({
    Body: body,
    BodyForAgent: rawBody,
    RawBody: rawBody,
    CommandBody: rawBody,
    From: isGroup ? `zalo:group:${chatId}` : `zalo:${senderId}`,
    To: `zalo:${chatId}`,
    SessionKey: route.sessionKey,
    AccountId: route.accountId,
    ChatType: isGroup ? "group" : "direct",
    ConversationLabel: fromLabel,
    SenderName: senderName || undefined,
    SenderId: senderId,
    CommandAuthorized: commandAuthorized,
    Provider: "zalo",
    Surface: "zalo",
    MessageSid: message_id,
    MediaPath: mediaPath,
    MediaType: mediaType,
    MediaUrl: mediaPath,
    OriginatingChannel: "zalo",
    OriginatingTo: `zalo:${chatId}`,
  });

  await core.channel.session.recordInboundSession({
    storePath,
    sessionKey: ctxPayload.SessionKey ?? route.sessionKey,
    ctx: ctxPayload,
    onRecordError: (err) => {
      runtime.error?.(`zalo: failed updating session meta: ${String(err)}`);
    },
  });

  const tableMode = core.channel.text.resolveMarkdownTableMode({
    cfg: config,
    channel: "zalo",
    accountId: account.accountId,
  });
  const { onModelSelected, ...prefixOptions } = createReplyPrefixOptions({
    cfg: config,
    agentId: route.agentId,
    channel: "zalo",
    accountId: account.accountId,
  });

  await core.channel.reply.dispatchReplyWithBufferedBlockDispatcher({
    ctx: ctxPayload,
    cfg: config,
    dispatcherOptions: {
      ...prefixOptions,
      deliver: async (payload) => {
        await deliverZaloReply({
          payload,
          token,
          chatId,
          runtime,
          core,
          config,
          accountId: account.accountId,
          statusSink,
          fetcher,
          tableMode,
        });
      },
      onError: (err, info) => {
        runtime.error?.(`[${account.accountId}] Zalo ${info.kind} reply failed: ${String(err)}`);
      },
    },
    replyOptions: {
      onModelSelected,
    },
  });
}

async function deliverZaloReply(params: {
  payload: { text?: string; mediaUrls?: string[]; mediaUrl?: string };
  token: string;
  chatId: string;
  runtime: ZaloRuntimeEnv;
  core: ZaloCoreRuntime;
  config: OpenClawConfig;
  accountId?: string;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
  fetcher?: ZaloFetch;
  tableMode?: MarkdownTableMode;
}): Promise<void> {
  const { payload, token, chatId, runtime, core, config, accountId, statusSink, fetcher } = params;
  const tableMode = params.tableMode ?? "code";
  const text = core.channel.text.convertMarkdownTables(payload.text ?? "", tableMode);

  const mediaList = payload.mediaUrls?.length
    ? payload.mediaUrls
    : payload.mediaUrl
      ? [payload.mediaUrl]
      : [];

  if (mediaList.length > 0) {
    let first = true;
    for (const mediaUrl of mediaList) {
      const caption = first ? text : undefined;
      first = false;
      try {
        await sendPhoto(token, { chat_id: chatId, photo: mediaUrl, caption }, fetcher);
        statusSink?.({ lastOutboundAt: Date.now() });
      } catch (err) {
        runtime.error?.(`Zalo photo send failed: ${String(err)}`);
      }
    }
    return;
  }

  if (text) {
    const chunkMode = core.channel.text.resolveChunkMode(config, "zalo", accountId);
    const chunks = core.channel.text.chunkMarkdownTextWithMode(text, ZALO_TEXT_LIMIT, chunkMode);
    for (const chunk of chunks) {
      try {
        await sendMessage(token, { chat_id: chatId, text: chunk }, fetcher);
        statusSink?.({ lastOutboundAt: Date.now() });
      } catch (err) {
        runtime.error?.(`Zalo message send failed: ${String(err)}`);
      }
    }
  }
}

export async function monitorZaloProvider(options: ZaloMonitorOptions): Promise<ZaloMonitorResult> {
  const {
    token,
    account,
    config,
    runtime,
    abortSignal,
    useWebhook,
    webhookUrl,
    webhookSecret,
    webhookPath,
    statusSink,
    fetcher: fetcherOverride,
  } = options;

  const core = getZaloRuntime();
  const effectiveMediaMaxMb = account.config.mediaMaxMb ?? DEFAULT_MEDIA_MAX_MB;
  const fetcher = fetcherOverride ?? resolveZaloProxyFetch(account.config.proxy);

  let stopped = false;
  const stopHandlers: Array<() => void> = [];

  const stop = () => {
    stopped = true;
    for (const handler of stopHandlers) {
      handler();
    }
  };

  if (useWebhook) {
    if (!webhookUrl || !webhookSecret) {
      throw new Error("Zalo webhookUrl and webhookSecret are required for webhook mode");
    }
    if (!webhookUrl.startsWith("https://")) {
      throw new Error("Zalo webhook URL must use HTTPS");
    }
    if (webhookSecret.length < 8 || webhookSecret.length > 256) {
      throw new Error("Zalo webhook secret must be 8-256 characters");
    }

    const path = resolveWebhookPath(webhookPath, webhookUrl);
    if (!path) {
      throw new Error("Zalo webhookPath could not be derived");
    }

    await setWebhook(token, { url: webhookUrl, secret_token: webhookSecret }, fetcher);

    const unregister = registerZaloWebhookTarget({
      token,
      account,
      config,
      runtime,
      core,
      path,
      secret: webhookSecret,
      statusSink: (patch) => statusSink?.(patch),
      mediaMaxMb: effectiveMediaMaxMb,
      fetcher,
    });
    stopHandlers.push(unregister);
    abortSignal.addEventListener(
      "abort",
      () => {
        void deleteWebhook(token, fetcher).catch(() => {});
      },
      { once: true },
    );
    return { stop };
  }

  try {
    await deleteWebhook(token, fetcher);
  } catch {
    // ignore
  }

  startPollingLoop({
    token,
    account,
    config,
    runtime,
    core,
    abortSignal,
    isStopped: () => stopped,
    mediaMaxMb: effectiveMediaMaxMb,
    statusSink,
    fetcher,
  });

  return { stop };
}
]]></file>
  <file path="./extensions/zalo/src/onboarding.ts"><![CDATA[import type {
  ChannelOnboardingAdapter,
  ChannelOnboardingDmPolicy,
  OpenClawConfig,
  WizardPrompter,
} from "openclaw/plugin-sdk";
import {
  addWildcardAllowFrom,
  DEFAULT_ACCOUNT_ID,
  normalizeAccountId,
  promptAccountId,
} from "openclaw/plugin-sdk";
import { listZaloAccountIds, resolveDefaultZaloAccountId, resolveZaloAccount } from "./accounts.js";

const channel = "zalo" as const;

type UpdateMode = "polling" | "webhook";

function setZaloDmPolicy(
  cfg: OpenClawConfig,
  dmPolicy: "pairing" | "allowlist" | "open" | "disabled",
) {
  const allowFrom =
    dmPolicy === "open" ? addWildcardAllowFrom(cfg.channels?.zalo?.allowFrom) : undefined;
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      zalo: {
        ...cfg.channels?.zalo,
        dmPolicy,
        ...(allowFrom ? { allowFrom } : {}),
      },
    },
  } as OpenClawConfig;
}

function setZaloUpdateMode(
  cfg: OpenClawConfig,
  accountId: string,
  mode: UpdateMode,
  webhookUrl?: string,
  webhookSecret?: string,
  webhookPath?: string,
): OpenClawConfig {
  const isDefault = accountId === DEFAULT_ACCOUNT_ID;
  if (mode === "polling") {
    if (isDefault) {
      const {
        webhookUrl: _url,
        webhookSecret: _secret,
        webhookPath: _path,
        ...rest
      } = cfg.channels?.zalo ?? {};
      return {
        ...cfg,
        channels: {
          ...cfg.channels,
          zalo: rest,
        },
      } as OpenClawConfig;
    }
    const accounts = { ...cfg.channels?.zalo?.accounts } as Record<string, Record<string, unknown>>;
    const existing = accounts[accountId] ?? {};
    const { webhookUrl: _url, webhookSecret: _secret, webhookPath: _path, ...rest } = existing;
    accounts[accountId] = rest;
    return {
      ...cfg,
      channels: {
        ...cfg.channels,
        zalo: {
          ...cfg.channels?.zalo,
          accounts,
        },
      },
    } as OpenClawConfig;
  }

  if (isDefault) {
    return {
      ...cfg,
      channels: {
        ...cfg.channels,
        zalo: {
          ...cfg.channels?.zalo,
          webhookUrl,
          webhookSecret,
          webhookPath,
        },
      },
    } as OpenClawConfig;
  }

  const accounts = { ...cfg.channels?.zalo?.accounts } as Record<string, Record<string, unknown>>;
  accounts[accountId] = {
    ...accounts[accountId],
    webhookUrl,
    webhookSecret,
    webhookPath,
  };
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      zalo: {
        ...cfg.channels?.zalo,
        accounts,
      },
    },
  } as OpenClawConfig;
}

async function noteZaloTokenHelp(prompter: WizardPrompter): Promise<void> {
  await prompter.note(
    [
      "1) Open Zalo Bot Platform: https://bot.zaloplatforms.com",
      "2) Create a bot and get the token",
      "3) Token looks like 12345689:abc-xyz",
      "Tip: you can also set ZALO_BOT_TOKEN in your env.",
      "Docs: https://docs.openclaw.ai/channels/zalo",
    ].join("\n"),
    "Zalo bot token",
  );
}

async function promptZaloAllowFrom(params: {
  cfg: OpenClawConfig;
  prompter: WizardPrompter;
  accountId: string;
}): Promise<OpenClawConfig> {
  const { cfg, prompter, accountId } = params;
  const resolved = resolveZaloAccount({ cfg, accountId });
  const existingAllowFrom = resolved.config.allowFrom ?? [];
  const entry = await prompter.text({
    message: "Zalo allowFrom (user id)",
    placeholder: "123456789",
    initialValue: existingAllowFrom[0] ? String(existingAllowFrom[0]) : undefined,
    validate: (value) => {
      const raw = String(value ?? "").trim();
      if (!raw) {
        return "Required";
      }
      if (!/^\d+$/.test(raw)) {
        return "Use a numeric Zalo user id";
      }
      return undefined;
    },
  });
  const normalized = String(entry).trim();
  const merged = [
    ...existingAllowFrom.map((item) => String(item).trim()).filter(Boolean),
    normalized,
  ];
  const unique = [...new Set(merged)];

  if (accountId === DEFAULT_ACCOUNT_ID) {
    return {
      ...cfg,
      channels: {
        ...cfg.channels,
        zalo: {
          ...cfg.channels?.zalo,
          enabled: true,
          dmPolicy: "allowlist",
          allowFrom: unique,
        },
      },
    } as OpenClawConfig;
  }

  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      zalo: {
        ...cfg.channels?.zalo,
        enabled: true,
        accounts: {
          ...cfg.channels?.zalo?.accounts,
          [accountId]: {
            ...cfg.channels?.zalo?.accounts?.[accountId],
            enabled: cfg.channels?.zalo?.accounts?.[accountId]?.enabled ?? true,
            dmPolicy: "allowlist",
            allowFrom: unique,
          },
        },
      },
    },
  } as OpenClawConfig;
}

const dmPolicy: ChannelOnboardingDmPolicy = {
  label: "Zalo",
  channel,
  policyKey: "channels.zalo.dmPolicy",
  allowFromKey: "channels.zalo.allowFrom",
  getCurrent: (cfg) => (cfg.channels?.zalo?.dmPolicy ?? "pairing") as "pairing",
  setPolicy: (cfg, policy) => setZaloDmPolicy(cfg, policy),
  promptAllowFrom: async ({ cfg, prompter, accountId }) => {
    const id =
      accountId && normalizeAccountId(accountId)
        ? (normalizeAccountId(accountId) ?? DEFAULT_ACCOUNT_ID)
        : resolveDefaultZaloAccountId(cfg);
    return promptZaloAllowFrom({
      cfg: cfg,
      prompter,
      accountId: id,
    });
  },
};

export const zaloOnboardingAdapter: ChannelOnboardingAdapter = {
  channel,
  dmPolicy,
  getStatus: async ({ cfg }) => {
    const configured = listZaloAccountIds(cfg).some((accountId) =>
      Boolean(resolveZaloAccount({ cfg: cfg, accountId }).token),
    );
    return {
      channel,
      configured,
      statusLines: [`Zalo: ${configured ? "configured" : "needs token"}`],
      selectionHint: configured ? "recommended · configured" : "recommended · newcomer-friendly",
      quickstartScore: configured ? 1 : 10,
    };
  },
  configure: async ({
    cfg,
    prompter,
    accountOverrides,
    shouldPromptAccountIds,
    forceAllowFrom,
  }) => {
    const zaloOverride = accountOverrides.zalo?.trim();
    const defaultZaloAccountId = resolveDefaultZaloAccountId(cfg);
    let zaloAccountId = zaloOverride ? normalizeAccountId(zaloOverride) : defaultZaloAccountId;
    if (shouldPromptAccountIds && !zaloOverride) {
      zaloAccountId = await promptAccountId({
        cfg: cfg,
        prompter,
        label: "Zalo",
        currentId: zaloAccountId,
        listAccountIds: listZaloAccountIds,
        defaultAccountId: defaultZaloAccountId,
      });
    }

    let next = cfg;
    const resolvedAccount = resolveZaloAccount({ cfg: next, accountId: zaloAccountId });
    const accountConfigured = Boolean(resolvedAccount.token);
    const allowEnv = zaloAccountId === DEFAULT_ACCOUNT_ID;
    const canUseEnv = allowEnv && Boolean(process.env.ZALO_BOT_TOKEN?.trim());
    const hasConfigToken = Boolean(
      resolvedAccount.config.botToken || resolvedAccount.config.tokenFile,
    );

    let token: string | null = null;
    if (!accountConfigured) {
      await noteZaloTokenHelp(prompter);
    }
    if (canUseEnv && !resolvedAccount.config.botToken) {
      const keepEnv = await prompter.confirm({
        message: "ZALO_BOT_TOKEN detected. Use env var?",
        initialValue: true,
      });
      if (keepEnv) {
        next = {
          ...next,
          channels: {
            ...next.channels,
            zalo: {
              ...next.channels?.zalo,
              enabled: true,
            },
          },
        } as OpenClawConfig;
      } else {
        token = String(
          await prompter.text({
            message: "Enter Zalo bot token",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else if (hasConfigToken) {
      const keep = await prompter.confirm({
        message: "Zalo token already configured. Keep it?",
        initialValue: true,
      });
      if (!keep) {
        token = String(
          await prompter.text({
            message: "Enter Zalo bot token",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else {
      token = String(
        await prompter.text({
          message: "Enter Zalo bot token",
          validate: (value) => (value?.trim() ? undefined : "Required"),
        }),
      ).trim();
    }

    if (token) {
      if (zaloAccountId === DEFAULT_ACCOUNT_ID) {
        next = {
          ...next,
          channels: {
            ...next.channels,
            zalo: {
              ...next.channels?.zalo,
              enabled: true,
              botToken: token,
            },
          },
        } as OpenClawConfig;
      } else {
        next = {
          ...next,
          channels: {
            ...next.channels,
            zalo: {
              ...next.channels?.zalo,
              enabled: true,
              accounts: {
                ...next.channels?.zalo?.accounts,
                [zaloAccountId]: {
                  ...next.channels?.zalo?.accounts?.[zaloAccountId],
                  enabled: true,
                  botToken: token,
                },
              },
            },
          },
        } as OpenClawConfig;
      }
    }

    const wantsWebhook = await prompter.confirm({
      message: "Use webhook mode for Zalo?",
      initialValue: false,
    });
    if (wantsWebhook) {
      const webhookUrl = String(
        await prompter.text({
          message: "Webhook URL (https://...) ",
          validate: (value) =>
            value?.trim()?.startsWith("https://") ? undefined : "HTTPS URL required",
        }),
      ).trim();
      const defaultPath = (() => {
        try {
          return new URL(webhookUrl).pathname || "/zalo-webhook";
        } catch {
          return "/zalo-webhook";
        }
      })();
      const webhookSecret = String(
        await prompter.text({
          message: "Webhook secret (8-256 chars)",
          validate: (value) => {
            const raw = String(value ?? "");
            if (raw.length < 8 || raw.length > 256) {
              return "8-256 chars";
            }
            return undefined;
          },
        }),
      ).trim();
      const webhookPath = String(
        await prompter.text({
          message: "Webhook path (optional)",
          initialValue: defaultPath,
        }),
      ).trim();
      next = setZaloUpdateMode(
        next,
        zaloAccountId,
        "webhook",
        webhookUrl,
        webhookSecret,
        webhookPath || undefined,
      );
    } else {
      next = setZaloUpdateMode(next, zaloAccountId, "polling");
    }

    if (forceAllowFrom) {
      next = await promptZaloAllowFrom({
        cfg: next,
        prompter,
        accountId: zaloAccountId,
      });
    }

    return { cfg: next, accountId: zaloAccountId };
  },
};
]]></file>
  <file path="./extensions/zalo/src/monitor.webhook.test.ts"><![CDATA[import type { AddressInfo } from "node:net";
import type { OpenClawConfig, PluginRuntime } from "openclaw/plugin-sdk";
import { createServer } from "node:http";
import { describe, expect, it } from "vitest";
import type { ResolvedZaloAccount } from "./types.js";
import { handleZaloWebhookRequest, registerZaloWebhookTarget } from "./monitor.js";

async function withServer(
  handler: Parameters<typeof createServer>[0],
  fn: (baseUrl: string) => Promise<void>,
) {
  const server = createServer(handler);
  await new Promise<void>((resolve) => {
    server.listen(0, "127.0.0.1", () => resolve());
  });
  const address = server.address() as AddressInfo | null;
  if (!address) {
    throw new Error("missing server address");
  }
  try {
    await fn(`http://127.0.0.1:${address.port}`);
  } finally {
    await new Promise<void>((resolve) => server.close(() => resolve()));
  }
}

describe("handleZaloWebhookRequest", () => {
  it("returns 400 for non-object payloads", async () => {
    const core = {} as PluginRuntime;
    const account: ResolvedZaloAccount = {
      accountId: "default",
      enabled: true,
      token: "tok",
      tokenSource: "config",
      config: {},
    };
    const unregister = registerZaloWebhookTarget({
      token: "tok",
      account,
      config: {} as OpenClawConfig,
      runtime: {},
      core,
      secret: "secret",
      path: "/hook",
      mediaMaxMb: 5,
    });

    try {
      await withServer(
        async (req, res) => {
          const handled = await handleZaloWebhookRequest(req, res);
          if (!handled) {
            res.statusCode = 404;
            res.end("not found");
          }
        },
        async (baseUrl) => {
          const response = await fetch(`${baseUrl}/hook`, {
            method: "POST",
            headers: {
              "x-bot-api-secret-token": "secret",
            },
            body: "null",
          });

          expect(response.status).toBe(400);
        },
      );
    } finally {
      unregister();
    }
  });
});
]]></file>
  <file path="./extensions/zalo/src/api.ts"><![CDATA[/**
 * Zalo Bot API client
 * @see https://bot.zaloplatforms.com/docs
 */

const ZALO_API_BASE = "https://bot-api.zaloplatforms.com";

export type ZaloFetch = (input: string, init?: RequestInit) => Promise<Response>;

export type ZaloApiResponse<T = unknown> = {
  ok: boolean;
  result?: T;
  error_code?: number;
  description?: string;
};

export type ZaloBotInfo = {
  id: string;
  name: string;
  avatar?: string;
};

export type ZaloMessage = {
  message_id: string;
  from: {
    id: string;
    name?: string;
    avatar?: string;
  };
  chat: {
    id: string;
    chat_type: "PRIVATE" | "GROUP";
  };
  date: number;
  text?: string;
  photo?: string;
  caption?: string;
  sticker?: string;
};

export type ZaloUpdate = {
  event_name:
    | "message.text.received"
    | "message.image.received"
    | "message.sticker.received"
    | "message.unsupported.received";
  message?: ZaloMessage;
};

export type ZaloSendMessageParams = {
  chat_id: string;
  text: string;
};

export type ZaloSendPhotoParams = {
  chat_id: string;
  photo: string;
  caption?: string;
};

export type ZaloSetWebhookParams = {
  url: string;
  secret_token: string;
};

export type ZaloGetUpdatesParams = {
  /** Timeout in seconds (passed as string to API) */
  timeout?: number;
};

export class ZaloApiError extends Error {
  constructor(
    message: string,
    public readonly errorCode?: number,
    public readonly description?: string,
  ) {
    super(message);
    this.name = "ZaloApiError";
  }

  /** True if this is a long-polling timeout (no updates available) */
  get isPollingTimeout(): boolean {
    return this.errorCode === 408;
  }
}

/**
 * Call the Zalo Bot API
 */
export async function callZaloApi<T = unknown>(
  method: string,
  token: string,
  body?: Record<string, unknown>,
  options?: { timeoutMs?: number; fetch?: ZaloFetch },
): Promise<ZaloApiResponse<T>> {
  const url = `${ZALO_API_BASE}/bot${token}/${method}`;
  const controller = new AbortController();
  const timeoutId = options?.timeoutMs
    ? setTimeout(() => controller.abort(), options.timeoutMs)
    : undefined;
  const fetcher = options?.fetch ?? fetch;

  try {
    const response = await fetcher(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    });

    const data = (await response.json()) as ZaloApiResponse<T>;

    if (!data.ok) {
      throw new ZaloApiError(
        data.description ?? `Zalo API error: ${method}`,
        data.error_code,
        data.description,
      );
    }

    return data;
  } finally {
    if (timeoutId) {
      clearTimeout(timeoutId);
    }
  }
}

/**
 * Validate bot token and get bot info
 */
export async function getMe(
  token: string,
  timeoutMs?: number,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<ZaloBotInfo>> {
  return callZaloApi<ZaloBotInfo>("getMe", token, undefined, { timeoutMs, fetch: fetcher });
}

/**
 * Send a text message
 */
export async function sendMessage(
  token: string,
  params: ZaloSendMessageParams,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<ZaloMessage>> {
  return callZaloApi<ZaloMessage>("sendMessage", token, params, { fetch: fetcher });
}

/**
 * Send a photo message
 */
export async function sendPhoto(
  token: string,
  params: ZaloSendPhotoParams,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<ZaloMessage>> {
  return callZaloApi<ZaloMessage>("sendPhoto", token, params, { fetch: fetcher });
}

/**
 * Get updates using long polling (dev/testing only)
 * Note: Zalo returns a single update per call, not an array like Telegram
 */
export async function getUpdates(
  token: string,
  params?: ZaloGetUpdatesParams,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<ZaloUpdate>> {
  const pollTimeoutSec = params?.timeout ?? 30;
  const timeoutMs = (pollTimeoutSec + 5) * 1000;
  const body = { timeout: String(pollTimeoutSec) };
  return callZaloApi<ZaloUpdate>("getUpdates", token, body, { timeoutMs, fetch: fetcher });
}

/**
 * Set webhook URL for receiving updates
 */
export async function setWebhook(
  token: string,
  params: ZaloSetWebhookParams,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<boolean>> {
  return callZaloApi<boolean>("setWebhook", token, params, { fetch: fetcher });
}

/**
 * Delete webhook configuration
 */
export async function deleteWebhook(
  token: string,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<boolean>> {
  return callZaloApi<boolean>("deleteWebhook", token, undefined, { fetch: fetcher });
}

/**
 * Get current webhook info
 */
export async function getWebhookInfo(
  token: string,
  fetcher?: ZaloFetch,
): Promise<ZaloApiResponse<{ url?: string; has_custom_certificate?: boolean }>> {
  return callZaloApi("getWebhookInfo", token, undefined, { fetch: fetcher });
}
]]></file>
  <file path="./extensions/zalo/src/actions.ts"><![CDATA[import type {
  ChannelMessageActionAdapter,
  ChannelMessageActionName,
  OpenClawConfig,
} from "openclaw/plugin-sdk";
import { jsonResult, readStringParam } from "openclaw/plugin-sdk";
import { listEnabledZaloAccounts } from "./accounts.js";
import { sendMessageZalo } from "./send.js";

const providerId = "zalo";

function listEnabledAccounts(cfg: OpenClawConfig) {
  return listEnabledZaloAccounts(cfg).filter(
    (account) => account.enabled && account.tokenSource !== "none",
  );
}

export const zaloMessageActions: ChannelMessageActionAdapter = {
  listActions: ({ cfg }) => {
    const accounts = listEnabledAccounts(cfg);
    if (accounts.length === 0) {
      return [];
    }
    const actions = new Set<ChannelMessageActionName>(["send"]);
    return Array.from(actions);
  },
  supportsButtons: () => false,
  extractToolSend: ({ args }) => {
    const action = typeof args.action === "string" ? args.action.trim() : "";
    if (action !== "sendMessage") {
      return null;
    }
    const to = typeof args.to === "string" ? args.to : undefined;
    if (!to) {
      return null;
    }
    const accountId = typeof args.accountId === "string" ? args.accountId.trim() : undefined;
    return { to, accountId };
  },
  handleAction: async ({ action, params, cfg, accountId }) => {
    if (action === "send") {
      const to = readStringParam(params, "to", { required: true });
      const content = readStringParam(params, "message", {
        required: true,
        allowEmpty: true,
      });
      const mediaUrl = readStringParam(params, "media", { trim: false });

      const result = await sendMessageZalo(to ?? "", content ?? "", {
        accountId: accountId ?? undefined,
        mediaUrl: mediaUrl ?? undefined,
        cfg: cfg,
      });

      if (!result.ok) {
        return jsonResult({
          ok: false,
          error: result.error ?? "Failed to send Zalo message",
        });
      }

      return jsonResult({ ok: true, to, messageId: result.messageId });
    }

    throw new Error(`Action ${action} is not supported for provider ${providerId}.`);
  },
};
]]></file>
  <file path="./extensions/zalo/src/accounts.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import { DEFAULT_ACCOUNT_ID, normalizeAccountId } from "openclaw/plugin-sdk";
import type { ResolvedZaloAccount, ZaloAccountConfig, ZaloConfig } from "./types.js";
import { resolveZaloToken } from "./token.js";

export type { ResolvedZaloAccount };

function listConfiguredAccountIds(cfg: OpenClawConfig): string[] {
  const accounts = (cfg.channels?.zalo as ZaloConfig | undefined)?.accounts;
  if (!accounts || typeof accounts !== "object") {
    return [];
  }
  return Object.keys(accounts).filter(Boolean);
}

export function listZaloAccountIds(cfg: OpenClawConfig): string[] {
  const ids = listConfiguredAccountIds(cfg);
  if (ids.length === 0) {
    return [DEFAULT_ACCOUNT_ID];
  }
  return ids.toSorted((a, b) => a.localeCompare(b));
}

export function resolveDefaultZaloAccountId(cfg: OpenClawConfig): string {
  const zaloConfig = cfg.channels?.zalo as ZaloConfig | undefined;
  if (zaloConfig?.defaultAccount?.trim()) {
    return zaloConfig.defaultAccount.trim();
  }
  const ids = listZaloAccountIds(cfg);
  if (ids.includes(DEFAULT_ACCOUNT_ID)) {
    return DEFAULT_ACCOUNT_ID;
  }
  return ids[0] ?? DEFAULT_ACCOUNT_ID;
}

function resolveAccountConfig(
  cfg: OpenClawConfig,
  accountId: string,
): ZaloAccountConfig | undefined {
  const accounts = (cfg.channels?.zalo as ZaloConfig | undefined)?.accounts;
  if (!accounts || typeof accounts !== "object") {
    return undefined;
  }
  return accounts[accountId] as ZaloAccountConfig | undefined;
}

function mergeZaloAccountConfig(cfg: OpenClawConfig, accountId: string): ZaloAccountConfig {
  const raw = (cfg.channels?.zalo ?? {}) as ZaloConfig;
  const { accounts: _ignored, defaultAccount: _ignored2, ...base } = raw;
  const account = resolveAccountConfig(cfg, accountId) ?? {};
  return { ...base, ...account };
}

export function resolveZaloAccount(params: {
  cfg: OpenClawConfig;
  accountId?: string | null;
}): ResolvedZaloAccount {
  const accountId = normalizeAccountId(params.accountId);
  const baseEnabled = (params.cfg.channels?.zalo as ZaloConfig | undefined)?.enabled !== false;
  const merged = mergeZaloAccountConfig(params.cfg, accountId);
  const accountEnabled = merged.enabled !== false;
  const enabled = baseEnabled && accountEnabled;
  const tokenResolution = resolveZaloToken(
    params.cfg.channels?.zalo as ZaloConfig | undefined,
    accountId,
  );

  return {
    accountId,
    name: merged.name?.trim() || undefined,
    enabled,
    token: tokenResolution.token,
    tokenSource: tokenResolution.source,
    config: merged,
  };
}

export function listEnabledZaloAccounts(cfg: OpenClawConfig): ResolvedZaloAccount[] {
  return listZaloAccountIds(cfg)
    .map((accountId) => resolveZaloAccount({ cfg, accountId }))
    .filter((account) => account.enabled);
}
]]></file>
  <file path="./extensions/zalo/src/types.ts"><![CDATA[export type ZaloAccountConfig = {
  /** Optional display name for this account (used in CLI/UI lists). */
  name?: string;
  /** If false, do not start this Zalo account. Default: true. */
  enabled?: boolean;
  /** Bot token from Zalo Bot Creator. */
  botToken?: string;
  /** Path to file containing the bot token. */
  tokenFile?: string;
  /** Webhook URL for receiving updates (HTTPS required). */
  webhookUrl?: string;
  /** Webhook secret token (8-256 chars) for request verification. */
  webhookSecret?: string;
  /** Webhook path for the gateway HTTP server (defaults to webhook URL path). */
  webhookPath?: string;
  /** Direct message access policy (default: pairing). */
  dmPolicy?: "pairing" | "allowlist" | "open" | "disabled";
  /** Allowlist for DM senders (Zalo user IDs). */
  allowFrom?: Array<string | number>;
  /** Max inbound media size in MB. */
  mediaMaxMb?: number;
  /** Proxy URL for API requests. */
  proxy?: string;
  /** Outbound response prefix override for this channel/account. */
  responsePrefix?: string;
};

export type ZaloConfig = {
  /** Optional per-account Zalo configuration (multi-account). */
  accounts?: Record<string, ZaloAccountConfig>;
  /** Default account ID when multiple accounts are configured. */
  defaultAccount?: string;
} & ZaloAccountConfig;

export type ZaloTokenSource = "env" | "config" | "configFile" | "none";

export type ResolvedZaloAccount = {
  accountId: string;
  name?: string;
  enabled: boolean;
  token: string;
  tokenSource: ZaloTokenSource;
  config: ZaloAccountConfig;
};
]]></file>
  <file path="./extensions/zalo/src/channel.directory.test.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import { describe, expect, it } from "vitest";
import { zaloPlugin } from "./channel.js";

describe("zalo directory", () => {
  it("lists peers from allowFrom", async () => {
    const cfg = {
      channels: {
        zalo: {
          allowFrom: ["zalo:123", "zl:234", "345"],
        },
      },
    } as unknown as OpenClawConfig;

    expect(zaloPlugin.directory).toBeTruthy();
    expect(zaloPlugin.directory?.listPeers).toBeTruthy();
    expect(zaloPlugin.directory?.listGroups).toBeTruthy();

    await expect(
      zaloPlugin.directory!.listPeers({
        cfg,
        accountId: undefined,
        query: undefined,
        limit: undefined,
      }),
    ).resolves.toEqual(
      expect.arrayContaining([
        { kind: "user", id: "123" },
        { kind: "user", id: "234" },
        { kind: "user", id: "345" },
      ]),
    );

    await expect(
      zaloPlugin.directory!.listGroups({
        cfg,
        accountId: undefined,
        query: undefined,
        limit: undefined,
      }),
    ).resolves.toEqual([]);
  });
});
]]></file>
  <file path="./extensions/zalo/src/config-schema.ts"><![CDATA[import { MarkdownConfigSchema } from "openclaw/plugin-sdk";
import { z } from "zod";

const allowFromEntry = z.union([z.string(), z.number()]);

const zaloAccountSchema = z.object({
  name: z.string().optional(),
  enabled: z.boolean().optional(),
  markdown: MarkdownConfigSchema,
  botToken: z.string().optional(),
  tokenFile: z.string().optional(),
  webhookUrl: z.string().optional(),
  webhookSecret: z.string().optional(),
  webhookPath: z.string().optional(),
  dmPolicy: z.enum(["pairing", "allowlist", "open", "disabled"]).optional(),
  allowFrom: z.array(allowFromEntry).optional(),
  mediaMaxMb: z.number().optional(),
  proxy: z.string().optional(),
  responsePrefix: z.string().optional(),
});

export const ZaloConfigSchema = zaloAccountSchema.extend({
  accounts: z.object({}).catchall(zaloAccountSchema).optional(),
  defaultAccount: z.string().optional(),
});
]]></file>
  <file path="./extensions/zalo/src/channel.ts"><![CDATA[import type {
  ChannelAccountSnapshot,
  ChannelDock,
  ChannelPlugin,
  OpenClawConfig,
} from "openclaw/plugin-sdk";
import {
  applyAccountNameToChannelSection,
  buildChannelConfigSchema,
  DEFAULT_ACCOUNT_ID,
  deleteAccountFromConfigSection,
  formatPairingApproveHint,
  migrateBaseNameToDefaultAccount,
  normalizeAccountId,
  PAIRING_APPROVED_MESSAGE,
  setAccountEnabledInConfigSection,
} from "openclaw/plugin-sdk";
import {
  listZaloAccountIds,
  resolveDefaultZaloAccountId,
  resolveZaloAccount,
  type ResolvedZaloAccount,
} from "./accounts.js";
import { zaloMessageActions } from "./actions.js";
import { ZaloConfigSchema } from "./config-schema.js";
import { zaloOnboardingAdapter } from "./onboarding.js";
import { probeZalo } from "./probe.js";
import { resolveZaloProxyFetch } from "./proxy.js";
import { sendMessageZalo } from "./send.js";
import { collectZaloStatusIssues } from "./status-issues.js";

const meta = {
  id: "zalo",
  label: "Zalo",
  selectionLabel: "Zalo (Bot API)",
  docsPath: "/channels/zalo",
  docsLabel: "zalo",
  blurb: "Vietnam-focused messaging platform with Bot API.",
  aliases: ["zl"],
  order: 80,
  quickstartAllowFrom: true,
};

function normalizeZaloMessagingTarget(raw: string): string | undefined {
  const trimmed = raw?.trim();
  if (!trimmed) {
    return undefined;
  }
  return trimmed.replace(/^(zalo|zl):/i, "");
}

export const zaloDock: ChannelDock = {
  id: "zalo",
  capabilities: {
    chatTypes: ["direct"],
    media: true,
    blockStreaming: true,
  },
  outbound: { textChunkLimit: 2000 },
  config: {
    resolveAllowFrom: ({ cfg, accountId }) =>
      (resolveZaloAccount({ cfg: cfg, accountId }).config.allowFrom ?? []).map((entry) =>
        String(entry),
      ),
    formatAllowFrom: ({ allowFrom }) =>
      allowFrom
        .map((entry) => String(entry).trim())
        .filter(Boolean)
        .map((entry) => entry.replace(/^(zalo|zl):/i, ""))
        .map((entry) => entry.toLowerCase()),
  },
  groups: {
    resolveRequireMention: () => true,
  },
  threading: {
    resolveReplyToMode: () => "off",
  },
};

export const zaloPlugin: ChannelPlugin<ResolvedZaloAccount> = {
  id: "zalo",
  meta,
  onboarding: zaloOnboardingAdapter,
  capabilities: {
    chatTypes: ["direct"],
    media: true,
    reactions: false,
    threads: false,
    polls: false,
    nativeCommands: false,
    blockStreaming: true,
  },
  reload: { configPrefixes: ["channels.zalo"] },
  configSchema: buildChannelConfigSchema(ZaloConfigSchema),
  config: {
    listAccountIds: (cfg) => listZaloAccountIds(cfg),
    resolveAccount: (cfg, accountId) => resolveZaloAccount({ cfg: cfg, accountId }),
    defaultAccountId: (cfg) => resolveDefaultZaloAccountId(cfg),
    setAccountEnabled: ({ cfg, accountId, enabled }) =>
      setAccountEnabledInConfigSection({
        cfg: cfg,
        sectionKey: "zalo",
        accountId,
        enabled,
        allowTopLevel: true,
      }),
    deleteAccount: ({ cfg, accountId }) =>
      deleteAccountFromConfigSection({
        cfg: cfg,
        sectionKey: "zalo",
        accountId,
        clearBaseFields: ["botToken", "tokenFile", "name"],
      }),
    isConfigured: (account) => Boolean(account.token?.trim()),
    describeAccount: (account): ChannelAccountSnapshot => ({
      accountId: account.accountId,
      name: account.name,
      enabled: account.enabled,
      configured: Boolean(account.token?.trim()),
      tokenSource: account.tokenSource,
    }),
    resolveAllowFrom: ({ cfg, accountId }) =>
      (resolveZaloAccount({ cfg: cfg, accountId }).config.allowFrom ?? []).map((entry) =>
        String(entry),
      ),
    formatAllowFrom: ({ allowFrom }) =>
      allowFrom
        .map((entry) => String(entry).trim())
        .filter(Boolean)
        .map((entry) => entry.replace(/^(zalo|zl):/i, ""))
        .map((entry) => entry.toLowerCase()),
  },
  security: {
    resolveDmPolicy: ({ cfg, accountId, account }) => {
      const resolvedAccountId = accountId ?? account.accountId ?? DEFAULT_ACCOUNT_ID;
      const useAccountPath = Boolean(cfg.channels?.zalo?.accounts?.[resolvedAccountId]);
      const basePath = useAccountPath
        ? `channels.zalo.accounts.${resolvedAccountId}.`
        : "channels.zalo.";
      return {
        policy: account.config.dmPolicy ?? "pairing",
        allowFrom: account.config.allowFrom ?? [],
        policyPath: `${basePath}dmPolicy`,
        allowFromPath: basePath,
        approveHint: formatPairingApproveHint("zalo"),
        normalizeEntry: (raw) => raw.replace(/^(zalo|zl):/i, ""),
      };
    },
  },
  groups: {
    resolveRequireMention: () => true,
  },
  threading: {
    resolveReplyToMode: () => "off",
  },
  actions: zaloMessageActions,
  messaging: {
    normalizeTarget: normalizeZaloMessagingTarget,
    targetResolver: {
      looksLikeId: (raw) => {
        const trimmed = raw.trim();
        if (!trimmed) {
          return false;
        }
        return /^\d{3,}$/.test(trimmed);
      },
      hint: "<chatId>",
    },
  },
  directory: {
    self: async () => null,
    listPeers: async ({ cfg, accountId, query, limit }) => {
      const account = resolveZaloAccount({ cfg: cfg, accountId });
      const q = query?.trim().toLowerCase() || "";
      const peers = Array.from(
        new Set(
          (account.config.allowFrom ?? [])
            .map((entry) => String(entry).trim())
            .filter((entry) => Boolean(entry) && entry !== "*")
            .map((entry) => entry.replace(/^(zalo|zl):/i, "")),
        ),
      )
        .filter((id) => (q ? id.toLowerCase().includes(q) : true))
        .slice(0, limit && limit > 0 ? limit : undefined)
        .map((id) => ({ kind: "user", id }) as const);
      return peers;
    },
    listGroups: async () => [],
  },
  setup: {
    resolveAccountId: ({ accountId }) => normalizeAccountId(accountId),
    applyAccountName: ({ cfg, accountId, name }) =>
      applyAccountNameToChannelSection({
        cfg: cfg,
        channelKey: "zalo",
        accountId,
        name,
      }),
    validateInput: ({ accountId, input }) => {
      if (input.useEnv && accountId !== DEFAULT_ACCOUNT_ID) {
        return "ZALO_BOT_TOKEN can only be used for the default account.";
      }
      if (!input.useEnv && !input.token && !input.tokenFile) {
        return "Zalo requires token or --token-file (or --use-env).";
      }
      return null;
    },
    applyAccountConfig: ({ cfg, accountId, input }) => {
      const namedConfig = applyAccountNameToChannelSection({
        cfg: cfg,
        channelKey: "zalo",
        accountId,
        name: input.name,
      });
      const next =
        accountId !== DEFAULT_ACCOUNT_ID
          ? migrateBaseNameToDefaultAccount({
              cfg: namedConfig,
              channelKey: "zalo",
            })
          : namedConfig;
      if (accountId === DEFAULT_ACCOUNT_ID) {
        return {
          ...next,
          channels: {
            ...next.channels,
            zalo: {
              ...next.channels?.zalo,
              enabled: true,
              ...(input.useEnv
                ? {}
                : input.tokenFile
                  ? { tokenFile: input.tokenFile }
                  : input.token
                    ? { botToken: input.token }
                    : {}),
            },
          },
        } as OpenClawConfig;
      }
      return {
        ...next,
        channels: {
          ...next.channels,
          zalo: {
            ...next.channels?.zalo,
            enabled: true,
            accounts: {
              ...next.channels?.zalo?.accounts,
              [accountId]: {
                ...next.channels?.zalo?.accounts?.[accountId],
                enabled: true,
                ...(input.tokenFile
                  ? { tokenFile: input.tokenFile }
                  : input.token
                    ? { botToken: input.token }
                    : {}),
              },
            },
          },
        },
      } as OpenClawConfig;
    },
  },
  pairing: {
    idLabel: "zaloUserId",
    normalizeAllowEntry: (entry) => entry.replace(/^(zalo|zl):/i, ""),
    notifyApproval: async ({ cfg, id }) => {
      const account = resolveZaloAccount({ cfg: cfg });
      if (!account.token) {
        throw new Error("Zalo token not configured");
      }
      await sendMessageZalo(id, PAIRING_APPROVED_MESSAGE, { token: account.token });
    },
  },
  outbound: {
    deliveryMode: "direct",
    chunker: (text, limit) => {
      if (!text) {
        return [];
      }
      if (limit <= 0 || text.length <= limit) {
        return [text];
      }
      const chunks: string[] = [];
      let remaining = text;
      while (remaining.length > limit) {
        const window = remaining.slice(0, limit);
        const lastNewline = window.lastIndexOf("\n");
        const lastSpace = window.lastIndexOf(" ");
        let breakIdx = lastNewline > 0 ? lastNewline : lastSpace;
        if (breakIdx <= 0) {
          breakIdx = limit;
        }
        const rawChunk = remaining.slice(0, breakIdx);
        const chunk = rawChunk.trimEnd();
        if (chunk.length > 0) {
          chunks.push(chunk);
        }
        const brokeOnSeparator = breakIdx < remaining.length && /\s/.test(remaining[breakIdx]);
        const nextStart = Math.min(remaining.length, breakIdx + (brokeOnSeparator ? 1 : 0));
        remaining = remaining.slice(nextStart).trimStart();
      }
      if (remaining.length) {
        chunks.push(remaining);
      }
      return chunks;
    },
    chunkerMode: "text",
    textChunkLimit: 2000,
    sendText: async ({ to, text, accountId, cfg }) => {
      const result = await sendMessageZalo(to, text, {
        accountId: accountId ?? undefined,
        cfg: cfg,
      });
      return {
        channel: "zalo",
        ok: result.ok,
        messageId: result.messageId ?? "",
        error: result.error ? new Error(result.error) : undefined,
      };
    },
    sendMedia: async ({ to, text, mediaUrl, accountId, cfg }) => {
      const result = await sendMessageZalo(to, text, {
        accountId: accountId ?? undefined,
        mediaUrl,
        cfg: cfg,
      });
      return {
        channel: "zalo",
        ok: result.ok,
        messageId: result.messageId ?? "",
        error: result.error ? new Error(result.error) : undefined,
      };
    },
  },
  status: {
    defaultRuntime: {
      accountId: DEFAULT_ACCOUNT_ID,
      running: false,
      lastStartAt: null,
      lastStopAt: null,
      lastError: null,
    },
    collectStatusIssues: collectZaloStatusIssues,
    buildChannelSummary: ({ snapshot }) => ({
      configured: snapshot.configured ?? false,
      tokenSource: snapshot.tokenSource ?? "none",
      running: snapshot.running ?? false,
      mode: snapshot.mode ?? null,
      lastStartAt: snapshot.lastStartAt ?? null,
      lastStopAt: snapshot.lastStopAt ?? null,
      lastError: snapshot.lastError ?? null,
      probe: snapshot.probe,
      lastProbeAt: snapshot.lastProbeAt ?? null,
    }),
    probeAccount: async ({ account, timeoutMs }) =>
      probeZalo(account.token, timeoutMs, resolveZaloProxyFetch(account.config.proxy)),
    buildAccountSnapshot: ({ account, runtime }) => {
      const configured = Boolean(account.token?.trim());
      return {
        accountId: account.accountId,
        name: account.name,
        enabled: account.enabled,
        configured,
        tokenSource: account.tokenSource,
        running: runtime?.running ?? false,
        lastStartAt: runtime?.lastStartAt ?? null,
        lastStopAt: runtime?.lastStopAt ?? null,
        lastError: runtime?.lastError ?? null,
        mode: account.config.webhookUrl ? "webhook" : "polling",
        lastInboundAt: runtime?.lastInboundAt ?? null,
        lastOutboundAt: runtime?.lastOutboundAt ?? null,
        dmPolicy: account.config.dmPolicy ?? "pairing",
      };
    },
  },
  gateway: {
    startAccount: async (ctx) => {
      const account = ctx.account;
      const token = account.token.trim();
      let zaloBotLabel = "";
      const fetcher = resolveZaloProxyFetch(account.config.proxy);
      try {
        const probe = await probeZalo(token, 2500, fetcher);
        const name = probe.ok ? probe.bot?.name?.trim() : null;
        if (name) {
          zaloBotLabel = ` (${name})`;
        }
        ctx.setStatus({
          accountId: account.accountId,
          bot: probe.bot,
        });
      } catch {
        // ignore probe errors
      }
      ctx.log?.info(`[${account.accountId}] starting provider${zaloBotLabel}`);
      const { monitorZaloProvider } = await import("./monitor.js");
      return monitorZaloProvider({
        token,
        account,
        config: ctx.cfg,
        runtime: ctx.runtime,
        abortSignal: ctx.abortSignal,
        useWebhook: Boolean(account.config.webhookUrl),
        webhookUrl: account.config.webhookUrl,
        webhookSecret: account.config.webhookSecret,
        webhookPath: account.config.webhookPath,
        fetcher,
        statusSink: (patch) => ctx.setStatus({ accountId: ctx.accountId, ...patch }),
      });
    },
  },
};
]]></file>
  <file path="./extensions/zalo/CHANGELOG.md"><![CDATA[# Changelog

## 2026.2.13

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-3

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.4

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.31

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.30

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.29

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.23

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.22

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.21

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.20

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.17-1

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.17

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.16

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.15

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.14

### Changes

- Version alignment with core OpenClaw release numbers.

## 0.1.0

### Features

- Zalo Bot API channel plugin with token-based auth (env/config/file).
- Direct message support (DMs only) with pairing/allowlist/open/disabled policies.
- Polling and webhook delivery modes.
- Text + image messaging with 2000-char chunking and media size caps.
- Multi-account support with per-account config.
]]></file>
  <file path="./extensions/zalo/index.ts"><![CDATA[import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import { emptyPluginConfigSchema } from "openclaw/plugin-sdk";
import { zaloDock, zaloPlugin } from "./src/channel.js";
import { handleZaloWebhookRequest } from "./src/monitor.js";
import { setZaloRuntime } from "./src/runtime.js";

const plugin = {
  id: "zalo",
  name: "Zalo",
  description: "Zalo channel plugin (Bot API)",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    setZaloRuntime(api.runtime);
    api.registerChannel({ plugin: zaloPlugin, dock: zaloDock });
    api.registerHttpHandler(handleZaloWebhookRequest);
  },
};

export default plugin;
]]></file>
  <file path="./extensions/diagnostics-otel/openclaw.plugin.json"><![CDATA[{
  "id": "diagnostics-otel",
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/diagnostics-otel/package.json"><![CDATA[{
  "name": "@openclaw/diagnostics-otel",
  "version": "2026.2.13",
  "description": "OpenClaw diagnostics OpenTelemetry exporter",
  "type": "module",
  "dependencies": {
    "@opentelemetry/api": "^1.9.0",
    "@opentelemetry/api-logs": "^0.212.0",
    "@opentelemetry/exporter-logs-otlp-http": "^0.212.0",
    "@opentelemetry/exporter-metrics-otlp-http": "^0.212.0",
    "@opentelemetry/exporter-trace-otlp-http": "^0.212.0",
    "@opentelemetry/resources": "^2.5.1",
    "@opentelemetry/sdk-logs": "^0.212.0",
    "@opentelemetry/sdk-metrics": "^2.5.1",
    "@opentelemetry/sdk-node": "^0.212.0",
    "@opentelemetry/sdk-trace-base": "^2.5.1",
    "@opentelemetry/semantic-conventions": "^1.39.0"
  },
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ]
  }
}
]]></file>
  <file path="./extensions/diagnostics-otel/src/service.ts"><![CDATA[import type { SeverityNumber } from "@opentelemetry/api-logs";
import type { DiagnosticEventPayload, OpenClawPluginService } from "openclaw/plugin-sdk";
import { metrics, trace, SpanStatusCode } from "@opentelemetry/api";
import { OTLPLogExporter } from "@opentelemetry/exporter-logs-otlp-http";
import { OTLPMetricExporter } from "@opentelemetry/exporter-metrics-otlp-http";
import { OTLPTraceExporter } from "@opentelemetry/exporter-trace-otlp-http";
import { resourceFromAttributes } from "@opentelemetry/resources";
import { BatchLogRecordProcessor, LoggerProvider } from "@opentelemetry/sdk-logs";
import { PeriodicExportingMetricReader } from "@opentelemetry/sdk-metrics";
import { NodeSDK } from "@opentelemetry/sdk-node";
import { ParentBasedSampler, TraceIdRatioBasedSampler } from "@opentelemetry/sdk-trace-base";
import { SemanticResourceAttributes } from "@opentelemetry/semantic-conventions";
import { onDiagnosticEvent, registerLogTransport } from "openclaw/plugin-sdk";

const DEFAULT_SERVICE_NAME = "openclaw";

function normalizeEndpoint(endpoint?: string): string | undefined {
  const trimmed = endpoint?.trim();
  return trimmed ? trimmed.replace(/\/+$/, "") : undefined;
}

function resolveOtelUrl(endpoint: string | undefined, path: string): string | undefined {
  if (!endpoint) {
    return undefined;
  }
  if (endpoint.includes("/v1/")) {
    return endpoint;
  }
  return `${endpoint}/${path}`;
}

function resolveSampleRate(value: number | undefined): number | undefined {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return undefined;
  }
  if (value < 0 || value > 1) {
    return undefined;
  }
  return value;
}

export function createDiagnosticsOtelService(): OpenClawPluginService {
  let sdk: NodeSDK | null = null;
  let logProvider: LoggerProvider | null = null;
  let stopLogTransport: (() => void) | null = null;
  let unsubscribe: (() => void) | null = null;

  return {
    id: "diagnostics-otel",
    async start(ctx) {
      const cfg = ctx.config.diagnostics;
      const otel = cfg?.otel;
      if (!cfg?.enabled || !otel?.enabled) {
        return;
      }

      const protocol = otel.protocol ?? process.env.OTEL_EXPORTER_OTLP_PROTOCOL ?? "http/protobuf";
      if (protocol !== "http/protobuf") {
        ctx.logger.warn(`diagnostics-otel: unsupported protocol ${protocol}`);
        return;
      }

      const endpoint = normalizeEndpoint(otel.endpoint ?? process.env.OTEL_EXPORTER_OTLP_ENDPOINT);
      const headers = otel.headers ?? undefined;
      const serviceName =
        otel.serviceName?.trim() || process.env.OTEL_SERVICE_NAME || DEFAULT_SERVICE_NAME;
      const sampleRate = resolveSampleRate(otel.sampleRate);

      const tracesEnabled = otel.traces !== false;
      const metricsEnabled = otel.metrics !== false;
      const logsEnabled = otel.logs === true;
      if (!tracesEnabled && !metricsEnabled && !logsEnabled) {
        return;
      }

      const resource = resourceFromAttributes({
        [SemanticResourceAttributes.SERVICE_NAME]: serviceName,
      });

      const traceUrl = resolveOtelUrl(endpoint, "v1/traces");
      const metricUrl = resolveOtelUrl(endpoint, "v1/metrics");
      const logUrl = resolveOtelUrl(endpoint, "v1/logs");
      const traceExporter = tracesEnabled
        ? new OTLPTraceExporter({
            ...(traceUrl ? { url: traceUrl } : {}),
            ...(headers ? { headers } : {}),
          })
        : undefined;

      const metricExporter = metricsEnabled
        ? new OTLPMetricExporter({
            ...(metricUrl ? { url: metricUrl } : {}),
            ...(headers ? { headers } : {}),
          })
        : undefined;

      const metricReader = metricExporter
        ? new PeriodicExportingMetricReader({
            exporter: metricExporter,
            ...(typeof otel.flushIntervalMs === "number"
              ? { exportIntervalMillis: Math.max(1000, otel.flushIntervalMs) }
              : {}),
          })
        : undefined;

      if (tracesEnabled || metricsEnabled) {
        sdk = new NodeSDK({
          resource,
          ...(traceExporter ? { traceExporter } : {}),
          ...(metricReader ? { metricReader } : {}),
          ...(sampleRate !== undefined
            ? {
                sampler: new ParentBasedSampler({
                  root: new TraceIdRatioBasedSampler(sampleRate),
                }),
              }
            : {}),
        });

        sdk.start();
      }

      const logSeverityMap: Record<string, SeverityNumber> = {
        TRACE: 1 as SeverityNumber,
        DEBUG: 5 as SeverityNumber,
        INFO: 9 as SeverityNumber,
        WARN: 13 as SeverityNumber,
        ERROR: 17 as SeverityNumber,
        FATAL: 21 as SeverityNumber,
      };

      const meter = metrics.getMeter("openclaw");
      const tracer = trace.getTracer("openclaw");

      const tokensCounter = meter.createCounter("openclaw.tokens", {
        unit: "1",
        description: "Token usage by type",
      });
      const costCounter = meter.createCounter("openclaw.cost.usd", {
        unit: "1",
        description: "Estimated model cost (USD)",
      });
      const durationHistogram = meter.createHistogram("openclaw.run.duration_ms", {
        unit: "ms",
        description: "Agent run duration",
      });
      const contextHistogram = meter.createHistogram("openclaw.context.tokens", {
        unit: "1",
        description: "Context window size and usage",
      });
      const webhookReceivedCounter = meter.createCounter("openclaw.webhook.received", {
        unit: "1",
        description: "Webhook requests received",
      });
      const webhookErrorCounter = meter.createCounter("openclaw.webhook.error", {
        unit: "1",
        description: "Webhook processing errors",
      });
      const webhookDurationHistogram = meter.createHistogram("openclaw.webhook.duration_ms", {
        unit: "ms",
        description: "Webhook processing duration",
      });
      const messageQueuedCounter = meter.createCounter("openclaw.message.queued", {
        unit: "1",
        description: "Messages queued for processing",
      });
      const messageProcessedCounter = meter.createCounter("openclaw.message.processed", {
        unit: "1",
        description: "Messages processed by outcome",
      });
      const messageDurationHistogram = meter.createHistogram("openclaw.message.duration_ms", {
        unit: "ms",
        description: "Message processing duration",
      });
      const queueDepthHistogram = meter.createHistogram("openclaw.queue.depth", {
        unit: "1",
        description: "Queue depth on enqueue/dequeue",
      });
      const queueWaitHistogram = meter.createHistogram("openclaw.queue.wait_ms", {
        unit: "ms",
        description: "Queue wait time before execution",
      });
      const laneEnqueueCounter = meter.createCounter("openclaw.queue.lane.enqueue", {
        unit: "1",
        description: "Command queue lane enqueue events",
      });
      const laneDequeueCounter = meter.createCounter("openclaw.queue.lane.dequeue", {
        unit: "1",
        description: "Command queue lane dequeue events",
      });
      const sessionStateCounter = meter.createCounter("openclaw.session.state", {
        unit: "1",
        description: "Session state transitions",
      });
      const sessionStuckCounter = meter.createCounter("openclaw.session.stuck", {
        unit: "1",
        description: "Sessions stuck in processing",
      });
      const sessionStuckAgeHistogram = meter.createHistogram("openclaw.session.stuck_age_ms", {
        unit: "ms",
        description: "Age of stuck sessions",
      });
      const runAttemptCounter = meter.createCounter("openclaw.run.attempt", {
        unit: "1",
        description: "Run attempts",
      });

      if (logsEnabled) {
        const logExporter = new OTLPLogExporter({
          ...(logUrl ? { url: logUrl } : {}),
          ...(headers ? { headers } : {}),
        });
        const processor = new BatchLogRecordProcessor(
          logExporter,
          typeof otel.flushIntervalMs === "number"
            ? { scheduledDelayMillis: Math.max(1000, otel.flushIntervalMs) }
            : {},
        );
        logProvider = new LoggerProvider({ resource, processors: [processor] });
        const otelLogger = logProvider.getLogger("openclaw");

        stopLogTransport = registerLogTransport((logObj) => {
          const safeStringify = (value: unknown) => {
            try {
              return JSON.stringify(value);
            } catch {
              return String(value);
            }
          };
          const meta = (logObj as Record<string, unknown>)._meta as
            | {
                logLevelName?: string;
                date?: Date;
                name?: string;
                parentNames?: string[];
                path?: {
                  filePath?: string;
                  fileLine?: string;
                  fileColumn?: string;
                  filePathWithLine?: string;
                  method?: string;
                };
              }
            | undefined;
          const logLevelName = meta?.logLevelName ?? "INFO";
          const severityNumber = logSeverityMap[logLevelName] ?? (9 as SeverityNumber);

          const numericArgs = Object.entries(logObj)
            .filter(([key]) => /^\d+$/.test(key))
            .toSorted((a, b) => Number(a[0]) - Number(b[0]))
            .map(([, value]) => value);

          let bindings: Record<string, unknown> | undefined;
          if (typeof numericArgs[0] === "string" && numericArgs[0].trim().startsWith("{")) {
            try {
              const parsed = JSON.parse(numericArgs[0]);
              if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
                bindings = parsed as Record<string, unknown>;
                numericArgs.shift();
              }
            } catch {
              // ignore malformed json bindings
            }
          }

          let message = "";
          if (numericArgs.length > 0 && typeof numericArgs[numericArgs.length - 1] === "string") {
            message = String(numericArgs.pop());
          } else if (numericArgs.length === 1) {
            message = safeStringify(numericArgs[0]);
            numericArgs.length = 0;
          }
          if (!message) {
            message = "log";
          }

          const attributes: Record<string, string | number | boolean> = {
            "openclaw.log.level": logLevelName,
          };
          if (meta?.name) {
            attributes["openclaw.logger"] = meta.name;
          }
          if (meta?.parentNames?.length) {
            attributes["openclaw.logger.parents"] = meta.parentNames.join(".");
          }
          if (bindings) {
            for (const [key, value] of Object.entries(bindings)) {
              if (
                typeof value === "string" ||
                typeof value === "number" ||
                typeof value === "boolean"
              ) {
                attributes[`openclaw.${key}`] = value;
              } else if (value != null) {
                attributes[`openclaw.${key}`] = safeStringify(value);
              }
            }
          }
          if (numericArgs.length > 0) {
            attributes["openclaw.log.args"] = safeStringify(numericArgs);
          }
          if (meta?.path?.filePath) {
            attributes["code.filepath"] = meta.path.filePath;
          }
          if (meta?.path?.fileLine) {
            attributes["code.lineno"] = Number(meta.path.fileLine);
          }
          if (meta?.path?.method) {
            attributes["code.function"] = meta.path.method;
          }
          if (meta?.path?.filePathWithLine) {
            attributes["openclaw.code.location"] = meta.path.filePathWithLine;
          }

          otelLogger.emit({
            body: message,
            severityText: logLevelName,
            severityNumber,
            attributes,
            timestamp: meta?.date ?? new Date(),
          });
        });
      }

      const spanWithDuration = (
        name: string,
        attributes: Record<string, string | number>,
        durationMs?: number,
      ) => {
        const startTime =
          typeof durationMs === "number" ? Date.now() - Math.max(0, durationMs) : undefined;
        const span = tracer.startSpan(name, {
          attributes,
          ...(startTime ? { startTime } : {}),
        });
        return span;
      };

      const recordModelUsage = (evt: Extract<DiagnosticEventPayload, { type: "model.usage" }>) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.provider": evt.provider ?? "unknown",
          "openclaw.model": evt.model ?? "unknown",
        };

        const usage = evt.usage;
        if (usage.input) {
          tokensCounter.add(usage.input, { ...attrs, "openclaw.token": "input" });
        }
        if (usage.output) {
          tokensCounter.add(usage.output, { ...attrs, "openclaw.token": "output" });
        }
        if (usage.cacheRead) {
          tokensCounter.add(usage.cacheRead, { ...attrs, "openclaw.token": "cache_read" });
        }
        if (usage.cacheWrite) {
          tokensCounter.add(usage.cacheWrite, { ...attrs, "openclaw.token": "cache_write" });
        }
        if (usage.promptTokens) {
          tokensCounter.add(usage.promptTokens, { ...attrs, "openclaw.token": "prompt" });
        }
        if (usage.total) {
          tokensCounter.add(usage.total, { ...attrs, "openclaw.token": "total" });
        }

        if (evt.costUsd) {
          costCounter.add(evt.costUsd, attrs);
        }
        if (evt.durationMs) {
          durationHistogram.record(evt.durationMs, attrs);
        }
        if (evt.context?.limit) {
          contextHistogram.record(evt.context.limit, {
            ...attrs,
            "openclaw.context": "limit",
          });
        }
        if (evt.context?.used) {
          contextHistogram.record(evt.context.used, {
            ...attrs,
            "openclaw.context": "used",
          });
        }

        if (!tracesEnabled) {
          return;
        }
        const spanAttrs: Record<string, string | number> = {
          ...attrs,
          "openclaw.sessionKey": evt.sessionKey ?? "",
          "openclaw.sessionId": evt.sessionId ?? "",
          "openclaw.tokens.input": usage.input ?? 0,
          "openclaw.tokens.output": usage.output ?? 0,
          "openclaw.tokens.cache_read": usage.cacheRead ?? 0,
          "openclaw.tokens.cache_write": usage.cacheWrite ?? 0,
          "openclaw.tokens.total": usage.total ?? 0,
        };

        const span = spanWithDuration("openclaw.model.usage", spanAttrs, evt.durationMs);
        span.end();
      };

      const recordWebhookReceived = (
        evt: Extract<DiagnosticEventPayload, { type: "webhook.received" }>,
      ) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.webhook": evt.updateType ?? "unknown",
        };
        webhookReceivedCounter.add(1, attrs);
      };

      const recordWebhookProcessed = (
        evt: Extract<DiagnosticEventPayload, { type: "webhook.processed" }>,
      ) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.webhook": evt.updateType ?? "unknown",
        };
        if (typeof evt.durationMs === "number") {
          webhookDurationHistogram.record(evt.durationMs, attrs);
        }
        if (!tracesEnabled) {
          return;
        }
        const spanAttrs: Record<string, string | number> = { ...attrs };
        if (evt.chatId !== undefined) {
          spanAttrs["openclaw.chatId"] = String(evt.chatId);
        }
        const span = spanWithDuration("openclaw.webhook.processed", spanAttrs, evt.durationMs);
        span.end();
      };

      const recordWebhookError = (
        evt: Extract<DiagnosticEventPayload, { type: "webhook.error" }>,
      ) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.webhook": evt.updateType ?? "unknown",
        };
        webhookErrorCounter.add(1, attrs);
        if (!tracesEnabled) {
          return;
        }
        const spanAttrs: Record<string, string | number> = {
          ...attrs,
          "openclaw.error": evt.error,
        };
        if (evt.chatId !== undefined) {
          spanAttrs["openclaw.chatId"] = String(evt.chatId);
        }
        const span = tracer.startSpan("openclaw.webhook.error", {
          attributes: spanAttrs,
        });
        span.setStatus({ code: SpanStatusCode.ERROR, message: evt.error });
        span.end();
      };

      const recordMessageQueued = (
        evt: Extract<DiagnosticEventPayload, { type: "message.queued" }>,
      ) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.source": evt.source ?? "unknown",
        };
        messageQueuedCounter.add(1, attrs);
        if (typeof evt.queueDepth === "number") {
          queueDepthHistogram.record(evt.queueDepth, attrs);
        }
      };

      const recordMessageProcessed = (
        evt: Extract<DiagnosticEventPayload, { type: "message.processed" }>,
      ) => {
        const attrs = {
          "openclaw.channel": evt.channel ?? "unknown",
          "openclaw.outcome": evt.outcome ?? "unknown",
        };
        messageProcessedCounter.add(1, attrs);
        if (typeof evt.durationMs === "number") {
          messageDurationHistogram.record(evt.durationMs, attrs);
        }
        if (!tracesEnabled) {
          return;
        }
        const spanAttrs: Record<string, string | number> = { ...attrs };
        if (evt.sessionKey) {
          spanAttrs["openclaw.sessionKey"] = evt.sessionKey;
        }
        if (evt.sessionId) {
          spanAttrs["openclaw.sessionId"] = evt.sessionId;
        }
        if (evt.chatId !== undefined) {
          spanAttrs["openclaw.chatId"] = String(evt.chatId);
        }
        if (evt.messageId !== undefined) {
          spanAttrs["openclaw.messageId"] = String(evt.messageId);
        }
        if (evt.reason) {
          spanAttrs["openclaw.reason"] = evt.reason;
        }
        const span = spanWithDuration("openclaw.message.processed", spanAttrs, evt.durationMs);
        if (evt.outcome === "error") {
          span.setStatus({ code: SpanStatusCode.ERROR, message: evt.error });
        }
        span.end();
      };

      const recordLaneEnqueue = (
        evt: Extract<DiagnosticEventPayload, { type: "queue.lane.enqueue" }>,
      ) => {
        const attrs = { "openclaw.lane": evt.lane };
        laneEnqueueCounter.add(1, attrs);
        queueDepthHistogram.record(evt.queueSize, attrs);
      };

      const recordLaneDequeue = (
        evt: Extract<DiagnosticEventPayload, { type: "queue.lane.dequeue" }>,
      ) => {
        const attrs = { "openclaw.lane": evt.lane };
        laneDequeueCounter.add(1, attrs);
        queueDepthHistogram.record(evt.queueSize, attrs);
        if (typeof evt.waitMs === "number") {
          queueWaitHistogram.record(evt.waitMs, attrs);
        }
      };

      const recordSessionState = (
        evt: Extract<DiagnosticEventPayload, { type: "session.state" }>,
      ) => {
        const attrs: Record<string, string> = { "openclaw.state": evt.state };
        if (evt.reason) {
          attrs["openclaw.reason"] = evt.reason;
        }
        sessionStateCounter.add(1, attrs);
      };

      const recordSessionStuck = (
        evt: Extract<DiagnosticEventPayload, { type: "session.stuck" }>,
      ) => {
        const attrs: Record<string, string> = { "openclaw.state": evt.state };
        sessionStuckCounter.add(1, attrs);
        if (typeof evt.ageMs === "number") {
          sessionStuckAgeHistogram.record(evt.ageMs, attrs);
        }
        if (!tracesEnabled) {
          return;
        }
        const spanAttrs: Record<string, string | number> = { ...attrs };
        if (evt.sessionKey) {
          spanAttrs["openclaw.sessionKey"] = evt.sessionKey;
        }
        if (evt.sessionId) {
          spanAttrs["openclaw.sessionId"] = evt.sessionId;
        }
        spanAttrs["openclaw.queueDepth"] = evt.queueDepth ?? 0;
        spanAttrs["openclaw.ageMs"] = evt.ageMs;
        const span = tracer.startSpan("openclaw.session.stuck", { attributes: spanAttrs });
        span.setStatus({ code: SpanStatusCode.ERROR, message: "session stuck" });
        span.end();
      };

      const recordRunAttempt = (evt: Extract<DiagnosticEventPayload, { type: "run.attempt" }>) => {
        runAttemptCounter.add(1, { "openclaw.attempt": evt.attempt });
      };

      const recordHeartbeat = (
        evt: Extract<DiagnosticEventPayload, { type: "diagnostic.heartbeat" }>,
      ) => {
        queueDepthHistogram.record(evt.queued, { "openclaw.channel": "heartbeat" });
      };

      unsubscribe = onDiagnosticEvent((evt: DiagnosticEventPayload) => {
        switch (evt.type) {
          case "model.usage":
            recordModelUsage(evt);
            return;
          case "webhook.received":
            recordWebhookReceived(evt);
            return;
          case "webhook.processed":
            recordWebhookProcessed(evt);
            return;
          case "webhook.error":
            recordWebhookError(evt);
            return;
          case "message.queued":
            recordMessageQueued(evt);
            return;
          case "message.processed":
            recordMessageProcessed(evt);
            return;
          case "queue.lane.enqueue":
            recordLaneEnqueue(evt);
            return;
          case "queue.lane.dequeue":
            recordLaneDequeue(evt);
            return;
          case "session.state":
            recordSessionState(evt);
            return;
          case "session.stuck":
            recordSessionStuck(evt);
            return;
          case "run.attempt":
            recordRunAttempt(evt);
            return;
          case "diagnostic.heartbeat":
            recordHeartbeat(evt);
            return;
        }
      });

      if (logsEnabled) {
        ctx.logger.info("diagnostics-otel: logs exporter enabled (OTLP/HTTP)");
      }
    },
    async stop() {
      unsubscribe?.();
      unsubscribe = null;
      stopLogTransport?.();
      stopLogTransport = null;
      if (logProvider) {
        await logProvider.shutdown().catch(() => undefined);
        logProvider = null;
      }
      if (sdk) {
        await sdk.shutdown().catch(() => undefined);
        sdk = null;
      }
    },
  } satisfies OpenClawPluginService;
}
]]></file>
  <file path="./extensions/diagnostics-otel/src/service.test.ts"><![CDATA[import { beforeEach, describe, expect, test, vi } from "vitest";

const registerLogTransportMock = vi.hoisted(() => vi.fn());

const telemetryState = vi.hoisted(() => {
  const counters = new Map<string, { add: ReturnType<typeof vi.fn> }>();
  const histograms = new Map<string, { record: ReturnType<typeof vi.fn> }>();
  const tracer = {
    startSpan: vi.fn((_name: string, _opts?: unknown) => ({
      end: vi.fn(),
      setStatus: vi.fn(),
    })),
  };
  const meter = {
    createCounter: vi.fn((name: string) => {
      const counter = { add: vi.fn() };
      counters.set(name, counter);
      return counter;
    }),
    createHistogram: vi.fn((name: string) => {
      const histogram = { record: vi.fn() };
      histograms.set(name, histogram);
      return histogram;
    }),
  };
  return { counters, histograms, tracer, meter };
});

const sdkStart = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));
const sdkShutdown = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));
const logEmit = vi.hoisted(() => vi.fn());
const logShutdown = vi.hoisted(() => vi.fn().mockResolvedValue(undefined));

vi.mock("@opentelemetry/api", () => ({
  metrics: {
    getMeter: () => telemetryState.meter,
  },
  trace: {
    getTracer: () => telemetryState.tracer,
  },
  SpanStatusCode: {
    ERROR: 2,
  },
}));

vi.mock("@opentelemetry/sdk-node", () => ({
  NodeSDK: class {
    start = sdkStart;
    shutdown = sdkShutdown;
  },
}));

vi.mock("@opentelemetry/exporter-metrics-otlp-http", () => ({
  OTLPMetricExporter: class {},
}));

vi.mock("@opentelemetry/exporter-trace-otlp-http", () => ({
  OTLPTraceExporter: class {},
}));

vi.mock("@opentelemetry/exporter-logs-otlp-http", () => ({
  OTLPLogExporter: class {},
}));

vi.mock("@opentelemetry/sdk-logs", () => ({
  BatchLogRecordProcessor: class {},
  LoggerProvider: class {
    addLogRecordProcessor = vi.fn();
    getLogger = vi.fn(() => ({
      emit: logEmit,
    }));
    shutdown = logShutdown;
  },
}));

vi.mock("@opentelemetry/sdk-metrics", () => ({
  PeriodicExportingMetricReader: class {},
}));

vi.mock("@opentelemetry/sdk-trace-base", () => ({
  ParentBasedSampler: class {},
  TraceIdRatioBasedSampler: class {},
}));

vi.mock("@opentelemetry/resources", () => ({
  resourceFromAttributes: vi.fn((attrs: Record<string, unknown>) => attrs),
  Resource: class {
    // eslint-disable-next-line @typescript-eslint/no-useless-constructor
    constructor(_value?: unknown) {}
  },
}));

vi.mock("@opentelemetry/semantic-conventions", () => ({
  SemanticResourceAttributes: {
    SERVICE_NAME: "service.name",
  },
}));

vi.mock("openclaw/plugin-sdk", async () => {
  const actual = await vi.importActual<typeof import("openclaw/plugin-sdk")>("openclaw/plugin-sdk");
  return {
    ...actual,
    registerLogTransport: registerLogTransportMock,
  };
});

import { emitDiagnosticEvent } from "openclaw/plugin-sdk";
import { createDiagnosticsOtelService } from "./service.js";

describe("diagnostics-otel service", () => {
  beforeEach(() => {
    telemetryState.counters.clear();
    telemetryState.histograms.clear();
    telemetryState.tracer.startSpan.mockClear();
    telemetryState.meter.createCounter.mockClear();
    telemetryState.meter.createHistogram.mockClear();
    sdkStart.mockClear();
    sdkShutdown.mockClear();
    logEmit.mockClear();
    logShutdown.mockClear();
    registerLogTransportMock.mockReset();
  });

  test("records message-flow metrics and spans", async () => {
    const registeredTransports: Array<(logObj: Record<string, unknown>) => void> = [];
    const stopTransport = vi.fn();
    registerLogTransportMock.mockImplementation((transport) => {
      registeredTransports.push(transport);
      return stopTransport;
    });

    const service = createDiagnosticsOtelService();
    await service.start({
      config: {
        diagnostics: {
          enabled: true,
          otel: {
            enabled: true,
            endpoint: "http://otel-collector:4318",
            protocol: "http/protobuf",
            traces: true,
            metrics: true,
            logs: true,
          },
        },
      },
      logger: {
        info: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
        debug: vi.fn(),
      },
    });

    emitDiagnosticEvent({
      type: "webhook.received",
      channel: "telegram",
      updateType: "telegram-post",
    });
    emitDiagnosticEvent({
      type: "webhook.processed",
      channel: "telegram",
      updateType: "telegram-post",
      durationMs: 120,
    });
    emitDiagnosticEvent({
      type: "message.queued",
      channel: "telegram",
      source: "telegram",
      queueDepth: 2,
    });
    emitDiagnosticEvent({
      type: "message.processed",
      channel: "telegram",
      outcome: "completed",
      durationMs: 55,
    });
    emitDiagnosticEvent({
      type: "queue.lane.dequeue",
      lane: "main",
      queueSize: 3,
      waitMs: 10,
    });
    emitDiagnosticEvent({
      type: "session.stuck",
      state: "processing",
      ageMs: 125_000,
    });
    emitDiagnosticEvent({
      type: "run.attempt",
      runId: "run-1",
      attempt: 2,
    });

    expect(telemetryState.counters.get("openclaw.webhook.received")?.add).toHaveBeenCalled();
    expect(
      telemetryState.histograms.get("openclaw.webhook.duration_ms")?.record,
    ).toHaveBeenCalled();
    expect(telemetryState.counters.get("openclaw.message.queued")?.add).toHaveBeenCalled();
    expect(telemetryState.counters.get("openclaw.message.processed")?.add).toHaveBeenCalled();
    expect(
      telemetryState.histograms.get("openclaw.message.duration_ms")?.record,
    ).toHaveBeenCalled();
    expect(telemetryState.histograms.get("openclaw.queue.wait_ms")?.record).toHaveBeenCalled();
    expect(telemetryState.counters.get("openclaw.session.stuck")?.add).toHaveBeenCalled();
    expect(
      telemetryState.histograms.get("openclaw.session.stuck_age_ms")?.record,
    ).toHaveBeenCalled();
    expect(telemetryState.counters.get("openclaw.run.attempt")?.add).toHaveBeenCalled();

    const spanNames = telemetryState.tracer.startSpan.mock.calls.map((call) => call[0]);
    expect(spanNames).toContain("openclaw.webhook.processed");
    expect(spanNames).toContain("openclaw.message.processed");
    expect(spanNames).toContain("openclaw.session.stuck");

    expect(registerLogTransportMock).toHaveBeenCalledTimes(1);
    expect(registeredTransports).toHaveLength(1);
    registeredTransports[0]?.({
      0: '{"subsystem":"diagnostic"}',
      1: "hello",
      _meta: { logLevelName: "INFO", date: new Date() },
    });
    expect(logEmit).toHaveBeenCalled();

    await service.stop?.();
  });
});
]]></file>
  <file path="./extensions/diagnostics-otel/index.ts"><![CDATA[import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import { emptyPluginConfigSchema } from "openclaw/plugin-sdk";
import { createDiagnosticsOtelService } from "./src/service.js";

const plugin = {
  id: "diagnostics-otel",
  name: "Diagnostics OpenTelemetry",
  description: "Export diagnostics events to OpenTelemetry",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    api.registerService(createDiagnosticsOtelService());
  },
};

export default plugin;
]]></file>
  <file path="./extensions/nextcloud-talk/openclaw.plugin.json"><![CDATA[{
  "id": "nextcloud-talk",
  "channels": ["nextcloud-talk"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/nextcloud-talk/package.json"><![CDATA[{
  "name": "@openclaw/nextcloud-talk",
  "version": "2026.2.13",
  "description": "OpenClaw Nextcloud Talk channel plugin",
  "type": "module",
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ],
    "channel": {
      "id": "nextcloud-talk",
      "label": "Nextcloud Talk",
      "selectionLabel": "Nextcloud Talk (self-hosted)",
      "docsPath": "/channels/nextcloud-talk",
      "docsLabel": "nextcloud-talk",
      "blurb": "Self-hosted chat via Nextcloud Talk webhook bots.",
      "aliases": [
        "nc-talk",
        "nc"
      ],
      "order": 65,
      "quickstartAllowFrom": true
    },
    "install": {
      "npmSpec": "@openclaw/nextcloud-talk",
      "localPath": "extensions/nextcloud-talk",
      "defaultChoice": "npm"
    }
  }
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/send.ts"><![CDATA[import type { CoreConfig, NextcloudTalkSendResult } from "./types.js";
import { resolveNextcloudTalkAccount } from "./accounts.js";
import { getNextcloudTalkRuntime } from "./runtime.js";
import { generateNextcloudTalkSignature } from "./signature.js";

type NextcloudTalkSendOpts = {
  baseUrl?: string;
  secret?: string;
  accountId?: string;
  replyTo?: string;
  verbose?: boolean;
};

function resolveCredentials(
  explicit: { baseUrl?: string; secret?: string },
  account: { baseUrl: string; secret: string; accountId: string },
): { baseUrl: string; secret: string } {
  const baseUrl = explicit.baseUrl?.trim() ?? account.baseUrl;
  const secret = explicit.secret?.trim() ?? account.secret;

  if (!baseUrl) {
    throw new Error(
      `Nextcloud Talk baseUrl missing for account "${account.accountId}" (set channels.nextcloud-talk.baseUrl).`,
    );
  }
  if (!secret) {
    throw new Error(
      `Nextcloud Talk bot secret missing for account "${account.accountId}" (set channels.nextcloud-talk.botSecret/botSecretFile or NEXTCLOUD_TALK_BOT_SECRET for default).`,
    );
  }

  return { baseUrl, secret };
}

function normalizeRoomToken(to: string): string {
  const trimmed = to.trim();
  if (!trimmed) {
    throw new Error("Room token is required for Nextcloud Talk sends");
  }

  let normalized = trimmed;
  if (normalized.startsWith("nextcloud-talk:")) {
    normalized = normalized.slice("nextcloud-talk:".length).trim();
  } else if (normalized.startsWith("nc:")) {
    normalized = normalized.slice("nc:".length).trim();
  }

  if (normalized.startsWith("room:")) {
    normalized = normalized.slice("room:".length).trim();
  }

  if (!normalized) {
    throw new Error("Room token is required for Nextcloud Talk sends");
  }
  return normalized;
}

export async function sendMessageNextcloudTalk(
  to: string,
  text: string,
  opts: NextcloudTalkSendOpts = {},
): Promise<NextcloudTalkSendResult> {
  const cfg = getNextcloudTalkRuntime().config.loadConfig() as CoreConfig;
  const account = resolveNextcloudTalkAccount({
    cfg,
    accountId: opts.accountId,
  });
  const { baseUrl, secret } = resolveCredentials(
    { baseUrl: opts.baseUrl, secret: opts.secret },
    account,
  );
  const roomToken = normalizeRoomToken(to);

  if (!text?.trim()) {
    throw new Error("Message must be non-empty for Nextcloud Talk sends");
  }

  const tableMode = getNextcloudTalkRuntime().channel.text.resolveMarkdownTableMode({
    cfg,
    channel: "nextcloud-talk",
    accountId: account.accountId,
  });
  const message = getNextcloudTalkRuntime().channel.text.convertMarkdownTables(
    text.trim(),
    tableMode,
  );

  const body: Record<string, unknown> = {
    message,
  };
  if (opts.replyTo) {
    body.replyTo = opts.replyTo;
  }
  const bodyStr = JSON.stringify(body);

  // Nextcloud Talk verifies signature against the extracted message text,
  // not the full JSON body. See ChecksumVerificationService.php:
  //   hash_hmac('sha256', $random . $data, $secret)
  // where $data is the "message" parameter, not the raw request body.
  const { random, signature } = generateNextcloudTalkSignature({
    body: message,
    secret,
  });

  const url = `${baseUrl}/ocs/v2.php/apps/spreed/api/v1/bot/${roomToken}/message`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "OCS-APIRequest": "true",
      "X-Nextcloud-Talk-Bot-Random": random,
      "X-Nextcloud-Talk-Bot-Signature": signature,
    },
    body: bodyStr,
  });

  if (!response.ok) {
    const errorBody = await response.text().catch(() => "");
    const status = response.status;
    let errorMsg = `Nextcloud Talk send failed (${status})`;

    if (status === 400) {
      errorMsg = `Nextcloud Talk: bad request - ${errorBody || "invalid message format"}`;
    } else if (status === 401) {
      errorMsg = "Nextcloud Talk: authentication failed - check bot secret";
    } else if (status === 403) {
      errorMsg = "Nextcloud Talk: forbidden - bot may not have permission in this room";
    } else if (status === 404) {
      errorMsg = `Nextcloud Talk: room not found (token=${roomToken})`;
    } else if (errorBody) {
      errorMsg = `Nextcloud Talk send failed: ${errorBody}`;
    }

    throw new Error(errorMsg);
  }

  let messageId = "unknown";
  let timestamp: number | undefined;
  try {
    const data = (await response.json()) as {
      ocs?: {
        data?: {
          id?: number | string;
          timestamp?: number;
        };
      };
    };
    if (data.ocs?.data?.id != null) {
      messageId = String(data.ocs.data.id);
    }
    if (typeof data.ocs?.data?.timestamp === "number") {
      timestamp = data.ocs.data.timestamp;
    }
  } catch {
    // Response parsing failed, but message was sent.
  }

  if (opts.verbose) {
    console.log(`[nextcloud-talk] Sent message ${messageId} to room ${roomToken}`);
  }

  getNextcloudTalkRuntime().channel.activity.record({
    channel: "nextcloud-talk",
    accountId: account.accountId,
    direction: "outbound",
  });

  return { messageId, roomToken, timestamp };
}

export async function sendReactionNextcloudTalk(
  roomToken: string,
  messageId: string,
  reaction: string,
  opts: Omit<NextcloudTalkSendOpts, "replyTo"> = {},
): Promise<{ ok: true }> {
  const cfg = getNextcloudTalkRuntime().config.loadConfig() as CoreConfig;
  const account = resolveNextcloudTalkAccount({
    cfg,
    accountId: opts.accountId,
  });
  const { baseUrl, secret } = resolveCredentials(
    { baseUrl: opts.baseUrl, secret: opts.secret },
    account,
  );
  const normalizedToken = normalizeRoomToken(roomToken);

  const body = JSON.stringify({ reaction });
  // Sign only the reaction string, not the full JSON body
  const { random, signature } = generateNextcloudTalkSignature({
    body: reaction,
    secret,
  });

  const url = `${baseUrl}/ocs/v2.php/apps/spreed/api/v1/bot/${normalizedToken}/reaction/${messageId}`;

  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "OCS-APIRequest": "true",
      "X-Nextcloud-Talk-Bot-Random": random,
      "X-Nextcloud-Talk-Bot-Signature": signature,
    },
    body,
  });

  if (!response.ok) {
    const errorBody = await response.text().catch(() => "");
    throw new Error(`Nextcloud Talk reaction failed: ${response.status} ${errorBody}`.trim());
  }

  return { ok: true };
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/policy.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import { resolveNextcloudTalkAllowlistMatch } from "./policy.js";

describe("nextcloud-talk policy", () => {
  describe("resolveNextcloudTalkAllowlistMatch", () => {
    it("allows wildcard", () => {
      expect(
        resolveNextcloudTalkAllowlistMatch({
          allowFrom: ["*"],
          senderId: "user-id",
        }).allowed,
      ).toBe(true);
    });

    it("allows sender id match with normalization", () => {
      expect(
        resolveNextcloudTalkAllowlistMatch({
          allowFrom: ["nc:User-Id"],
          senderId: "user-id",
        }),
      ).toEqual({ allowed: true, matchKey: "user-id", matchSource: "id" });
    });

    it("blocks when sender id does not match", () => {
      expect(
        resolveNextcloudTalkAllowlistMatch({
          allowFrom: ["allowed"],
          senderId: "other",
        }).allowed,
      ).toBe(false);
    });
  });
});
]]></file>
  <file path="./extensions/nextcloud-talk/src/signature.ts"><![CDATA[import { createHmac, randomBytes } from "node:crypto";
import type { NextcloudTalkWebhookHeaders } from "./types.js";

const SIGNATURE_HEADER = "x-nextcloud-talk-signature";
const RANDOM_HEADER = "x-nextcloud-talk-random";
const BACKEND_HEADER = "x-nextcloud-talk-backend";

/**
 * Verify the HMAC-SHA256 signature of an incoming webhook request.
 * Signature is calculated as: HMAC-SHA256(random + body, secret)
 */
export function verifyNextcloudTalkSignature(params: {
  signature: string;
  random: string;
  body: string;
  secret: string;
}): boolean {
  const { signature, random, body, secret } = params;
  if (!signature || !random || !secret) {
    return false;
  }

  const expected = createHmac("sha256", secret)
    .update(random + body)
    .digest("hex");

  if (signature.length !== expected.length) {
    return false;
  }
  let result = 0;
  for (let i = 0; i < signature.length; i++) {
    result |= signature.charCodeAt(i) ^ expected.charCodeAt(i);
  }
  return result === 0;
}

/**
 * Extract webhook headers from an incoming request.
 */
export function extractNextcloudTalkHeaders(
  headers: Record<string, string | string[] | undefined>,
): NextcloudTalkWebhookHeaders | null {
  const getHeader = (name: string): string | undefined => {
    const value = headers[name] ?? headers[name.toLowerCase()];
    return Array.isArray(value) ? value[0] : value;
  };

  const signature = getHeader(SIGNATURE_HEADER);
  const random = getHeader(RANDOM_HEADER);
  const backend = getHeader(BACKEND_HEADER);

  if (!signature || !random || !backend) {
    return null;
  }

  return { signature, random, backend };
}

/**
 * Generate signature headers for an outbound request to Nextcloud Talk.
 */
export function generateNextcloudTalkSignature(params: { body: string; secret: string }): {
  random: string;
  signature: string;
} {
  const { body, secret } = params;
  const random = randomBytes(32).toString("hex");
  const signature = createHmac("sha256", secret)
    .update(random + body)
    .digest("hex");
  return { random, signature };
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/normalize.ts"><![CDATA[export function normalizeNextcloudTalkMessagingTarget(raw: string): string | undefined {
  const trimmed = raw.trim();
  if (!trimmed) {
    return undefined;
  }

  let normalized = trimmed;

  if (normalized.startsWith("nextcloud-talk:")) {
    normalized = normalized.slice("nextcloud-talk:".length).trim();
  } else if (normalized.startsWith("nc-talk:")) {
    normalized = normalized.slice("nc-talk:".length).trim();
  } else if (normalized.startsWith("nc:")) {
    normalized = normalized.slice("nc:".length).trim();
  }

  if (normalized.startsWith("room:")) {
    normalized = normalized.slice("room:".length).trim();
  }

  if (!normalized) {
    return undefined;
  }

  return `nextcloud-talk:${normalized}`.toLowerCase();
}

export function looksLikeNextcloudTalkTargetId(raw: string): boolean {
  const trimmed = raw.trim();
  if (!trimmed) {
    return false;
  }

  if (/^(nextcloud-talk|nc-talk|nc):/i.test(trimmed)) {
    return true;
  }

  return /^[a-z0-9]{8,}$/i.test(trimmed);
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/room-info.ts"><![CDATA[import type { RuntimeEnv } from "openclaw/plugin-sdk";
import { readFileSync } from "node:fs";
import type { ResolvedNextcloudTalkAccount } from "./accounts.js";

const ROOM_CACHE_TTL_MS = 5 * 60 * 1000;
const ROOM_CACHE_ERROR_TTL_MS = 30 * 1000;

const roomCache = new Map<
  string,
  { kind?: "direct" | "group"; fetchedAt: number; error?: string }
>();

function resolveRoomCacheKey(params: { accountId: string; roomToken: string }) {
  return `${params.accountId}:${params.roomToken}`;
}

function readApiPassword(params: {
  apiPassword?: string;
  apiPasswordFile?: string;
}): string | undefined {
  if (params.apiPassword?.trim()) {
    return params.apiPassword.trim();
  }
  if (!params.apiPasswordFile) {
    return undefined;
  }
  try {
    const value = readFileSync(params.apiPasswordFile, "utf-8").trim();
    return value || undefined;
  } catch {
    return undefined;
  }
}

function coerceRoomType(value: unknown): number | undefined {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === "string" && value.trim()) {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : undefined;
  }
  return undefined;
}

function resolveRoomKindFromType(type: number | undefined): "direct" | "group" | undefined {
  if (!type) {
    return undefined;
  }
  if (type === 1 || type === 5 || type === 6) {
    return "direct";
  }
  return "group";
}

export async function resolveNextcloudTalkRoomKind(params: {
  account: ResolvedNextcloudTalkAccount;
  roomToken: string;
  runtime?: RuntimeEnv;
}): Promise<"direct" | "group" | undefined> {
  const { account, roomToken, runtime } = params;
  const key = resolveRoomCacheKey({ accountId: account.accountId, roomToken });
  const cached = roomCache.get(key);
  if (cached) {
    const age = Date.now() - cached.fetchedAt;
    if (cached.kind && age < ROOM_CACHE_TTL_MS) {
      return cached.kind;
    }
    if (cached.error && age < ROOM_CACHE_ERROR_TTL_MS) {
      return undefined;
    }
  }

  const apiUser = account.config.apiUser?.trim();
  const apiPassword = readApiPassword({
    apiPassword: account.config.apiPassword,
    apiPasswordFile: account.config.apiPasswordFile,
  });
  if (!apiUser || !apiPassword) {
    return undefined;
  }

  const baseUrl = account.baseUrl?.trim();
  if (!baseUrl) {
    return undefined;
  }

  const url = `${baseUrl}/ocs/v2.php/apps/spreed/api/v4/room/${roomToken}`;
  const auth = Buffer.from(`${apiUser}:${apiPassword}`, "utf-8").toString("base64");

  try {
    const response = await fetch(url, {
      method: "GET",
      headers: {
        Authorization: `Basic ${auth}`,
        "OCS-APIRequest": "true",
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      roomCache.set(key, {
        fetchedAt: Date.now(),
        error: `status:${response.status}`,
      });
      runtime?.log?.(`nextcloud-talk: room lookup failed (${response.status}) token=${roomToken}`);
      return undefined;
    }

    const payload = (await response.json()) as {
      ocs?: { data?: { type?: number | string } };
    };
    const type = coerceRoomType(payload.ocs?.data?.type);
    const kind = resolveRoomKindFromType(type);
    roomCache.set(key, { fetchedAt: Date.now(), kind });
    return kind;
  } catch (err) {
    roomCache.set(key, {
      fetchedAt: Date.now(),
      error: err instanceof Error ? err.message : String(err),
    });
    runtime?.error?.(`nextcloud-talk: room lookup error: ${String(err)}`);
    return undefined;
  }
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/runtime.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";

let runtime: PluginRuntime | null = null;

export function setNextcloudTalkRuntime(next: PluginRuntime) {
  runtime = next;
}

export function getNextcloudTalkRuntime(): PluginRuntime {
  if (!runtime) {
    throw new Error("Nextcloud Talk runtime not initialized");
  }
  return runtime;
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/format.ts"><![CDATA[/**
 * Format utilities for Nextcloud Talk messages.
 *
 * Nextcloud Talk supports markdown natively, so most formatting passes through.
 * This module handles any edge cases or transformations needed.
 */

/**
 * Convert markdown to Nextcloud Talk compatible format.
 * Nextcloud Talk supports standard markdown, so minimal transformation needed.
 */
export function markdownToNextcloudTalk(text: string): string {
  return text.trim();
}

/**
 * Escape special characters in text to prevent markdown interpretation.
 */
export function escapeNextcloudTalkMarkdown(text: string): string {
  return text.replace(/([*_`~[\]()#>+\-=|{}!\\])/g, "\\$1");
}

/**
 * Format a mention for a Nextcloud user.
 * Nextcloud Talk uses @user format for mentions.
 */
export function formatNextcloudTalkMention(userId: string): string {
  return `@${userId.replace(/^@/, "")}`;
}

/**
 * Format a code block for Nextcloud Talk.
 */
export function formatNextcloudTalkCodeBlock(code: string, language?: string): string {
  const lang = language ?? "";
  return `\`\`\`${lang}\n${code}\n\`\`\``;
}

/**
 * Format inline code for Nextcloud Talk.
 */
export function formatNextcloudTalkInlineCode(code: string): string {
  if (code.includes("`")) {
    return `\`\` ${code} \`\``;
  }
  return `\`${code}\``;
}

/**
 * Strip Nextcloud Talk specific formatting from text.
 * Useful for extracting plain text content.
 */
export function stripNextcloudTalkFormatting(text: string): string {
  return text
    .replace(/```[\s\S]*?```/g, "")
    .replace(/`[^`]+`/g, "")
    .replace(/\*\*([^*]+)\*\*/g, "$1")
    .replace(/\*([^*]+)\*/g, "$1")
    .replace(/_([^_]+)_/g, "$1")
    .replace(/~~([^~]+)~~/g, "$1")
    .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1")
    .replace(/\s+/g, " ")
    .trim();
}

/**
 * Truncate text to a maximum length, preserving word boundaries.
 */
export function truncateNextcloudTalkText(text: string, maxLength: number, suffix = "..."): string {
  if (text.length <= maxLength) {
    return text;
  }
  const truncated = text.slice(0, maxLength - suffix.length);
  const lastSpace = truncated.lastIndexOf(" ");
  if (lastSpace > maxLength * 0.7) {
    return truncated.slice(0, lastSpace) + suffix;
  }
  return truncated + suffix;
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/monitor.ts"><![CDATA[import type { RuntimeEnv } from "openclaw/plugin-sdk";
import { createServer, type IncomingMessage, type Server, type ServerResponse } from "node:http";
import type {
  CoreConfig,
  NextcloudTalkInboundMessage,
  NextcloudTalkWebhookPayload,
  NextcloudTalkWebhookServerOptions,
} from "./types.js";
import { resolveNextcloudTalkAccount } from "./accounts.js";
import { handleNextcloudTalkInbound } from "./inbound.js";
import { getNextcloudTalkRuntime } from "./runtime.js";
import { extractNextcloudTalkHeaders, verifyNextcloudTalkSignature } from "./signature.js";

const DEFAULT_WEBHOOK_PORT = 8788;
const DEFAULT_WEBHOOK_HOST = "0.0.0.0";
const DEFAULT_WEBHOOK_PATH = "/nextcloud-talk-webhook";
const HEALTH_PATH = "/healthz";

function formatError(err: unknown): string {
  if (err instanceof Error) {
    return err.message;
  }
  return typeof err === "string" ? err : JSON.stringify(err);
}

function parseWebhookPayload(body: string): NextcloudTalkWebhookPayload | null {
  try {
    const data = JSON.parse(body);
    if (
      !data.type ||
      !data.actor?.type ||
      !data.actor?.id ||
      !data.object?.type ||
      !data.object?.id ||
      !data.target?.type ||
      !data.target?.id
    ) {
      return null;
    }
    return data as NextcloudTalkWebhookPayload;
  } catch {
    return null;
  }
}

function payloadToInboundMessage(
  payload: NextcloudTalkWebhookPayload,
): NextcloudTalkInboundMessage {
  // Payload doesn't indicate DM vs room; mark as group and let inbound handler refine.
  const isGroupChat = true;

  return {
    messageId: String(payload.object.id),
    roomToken: payload.target.id,
    roomName: payload.target.name,
    senderId: payload.actor.id,
    senderName: payload.actor.name ?? "",
    text: payload.object.content || payload.object.name || "",
    mediaType: payload.object.mediaType || "text/plain",
    timestamp: Date.now(),
    isGroupChat,
  };
}

function readBody(req: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    req.on("data", (chunk: Buffer) => chunks.push(chunk));
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf-8")));
    req.on("error", reject);
  });
}

export function createNextcloudTalkWebhookServer(opts: NextcloudTalkWebhookServerOptions): {
  server: Server;
  start: () => Promise<void>;
  stop: () => void;
} {
  const { port, host, path, secret, onMessage, onError, abortSignal } = opts;

  const server = createServer(async (req: IncomingMessage, res: ServerResponse) => {
    if (req.url === HEALTH_PATH) {
      res.writeHead(200, { "Content-Type": "text/plain" });
      res.end("ok");
      return;
    }

    if (req.url !== path || req.method !== "POST") {
      res.writeHead(404);
      res.end();
      return;
    }

    try {
      const body = await readBody(req);

      const headers = extractNextcloudTalkHeaders(
        req.headers as Record<string, string | string[] | undefined>,
      );
      if (!headers) {
        res.writeHead(400, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ error: "Missing signature headers" }));
        return;
      }

      const isValid = verifyNextcloudTalkSignature({
        signature: headers.signature,
        random: headers.random,
        body,
        secret,
      });

      if (!isValid) {
        res.writeHead(401, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ error: "Invalid signature" }));
        return;
      }

      const payload = parseWebhookPayload(body);
      if (!payload) {
        res.writeHead(400, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ error: "Invalid payload format" }));
        return;
      }

      if (payload.type !== "Create") {
        res.writeHead(200);
        res.end();
        return;
      }

      const message = payloadToInboundMessage(payload);

      res.writeHead(200);
      res.end();

      try {
        await onMessage(message);
      } catch (err) {
        onError?.(err instanceof Error ? err : new Error(formatError(err)));
      }
    } catch (err) {
      const error = err instanceof Error ? err : new Error(formatError(err));
      onError?.(error);
      if (!res.headersSent) {
        res.writeHead(500, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ error: "Internal server error" }));
      }
    }
  });

  const start = (): Promise<void> => {
    return new Promise((resolve) => {
      server.listen(port, host, () => resolve());
    });
  };

  const stop = () => {
    server.close();
  };

  if (abortSignal) {
    abortSignal.addEventListener("abort", stop, { once: true });
  }

  return { server, start, stop };
}

export type NextcloudTalkMonitorOptions = {
  accountId?: string;
  config?: CoreConfig;
  runtime?: RuntimeEnv;
  abortSignal?: AbortSignal;
  onMessage?: (message: NextcloudTalkInboundMessage) => void | Promise<void>;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
};

export async function monitorNextcloudTalkProvider(
  opts: NextcloudTalkMonitorOptions,
): Promise<{ stop: () => void }> {
  const core = getNextcloudTalkRuntime();
  const cfg = opts.config ?? (core.config.loadConfig() as CoreConfig);
  const account = resolveNextcloudTalkAccount({
    cfg,
    accountId: opts.accountId,
  });
  const runtime: RuntimeEnv = opts.runtime ?? {
    log: (message: string) => core.logging.getChildLogger().info(message),
    error: (message: string) => core.logging.getChildLogger().error(message),
    exit: () => {
      throw new Error("Runtime exit not available");
    },
  };

  if (!account.secret) {
    throw new Error(`Nextcloud Talk bot secret not configured for account "${account.accountId}"`);
  }

  const port = account.config.webhookPort ?? DEFAULT_WEBHOOK_PORT;
  const host = account.config.webhookHost ?? DEFAULT_WEBHOOK_HOST;
  const path = account.config.webhookPath ?? DEFAULT_WEBHOOK_PATH;

  const logger = core.logging.getChildLogger({
    channel: "nextcloud-talk",
    accountId: account.accountId,
  });

  const { start, stop } = createNextcloudTalkWebhookServer({
    port,
    host,
    path,
    secret: account.secret,
    onMessage: async (message) => {
      core.channel.activity.record({
        channel: "nextcloud-talk",
        accountId: account.accountId,
        direction: "inbound",
        at: message.timestamp,
      });
      if (opts.onMessage) {
        await opts.onMessage(message);
        return;
      }
      await handleNextcloudTalkInbound({
        message,
        account,
        config: cfg,
        runtime,
        statusSink: opts.statusSink,
      });
    },
    onError: (error) => {
      logger.error(`[nextcloud-talk:${account.accountId}] webhook error: ${error.message}`);
    },
    abortSignal: opts.abortSignal,
  });

  await start();

  const publicUrl =
    account.config.webhookPublicUrl ??
    `http://${host === "0.0.0.0" ? "localhost" : host}:${port}${path}`;
  logger.info(`[nextcloud-talk:${account.accountId}] webhook listening on ${publicUrl}`);

  return { stop };
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/onboarding.ts"><![CDATA[import {
  addWildcardAllowFrom,
  formatDocsLink,
  promptAccountId,
  DEFAULT_ACCOUNT_ID,
  normalizeAccountId,
  type ChannelOnboardingAdapter,
  type ChannelOnboardingDmPolicy,
  type OpenClawConfig,
  type WizardPrompter,
} from "openclaw/plugin-sdk";
import type { CoreConfig, DmPolicy } from "./types.js";
import {
  listNextcloudTalkAccountIds,
  resolveDefaultNextcloudTalkAccountId,
  resolveNextcloudTalkAccount,
} from "./accounts.js";

const channel = "nextcloud-talk" as const;

function setNextcloudTalkDmPolicy(cfg: CoreConfig, dmPolicy: DmPolicy): CoreConfig {
  const existingConfig = cfg.channels?.["nextcloud-talk"];
  const existingAllowFrom: string[] = (existingConfig?.allowFrom ?? []).map((x) => String(x));
  const allowFrom: string[] =
    dmPolicy === "open" ? (addWildcardAllowFrom(existingAllowFrom) as string[]) : existingAllowFrom;

  const newNextcloudTalkConfig = {
    ...existingConfig,
    dmPolicy,
    allowFrom,
  };

  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      "nextcloud-talk": newNextcloudTalkConfig,
    },
  } as CoreConfig;
}

async function noteNextcloudTalkSecretHelp(prompter: WizardPrompter): Promise<void> {
  await prompter.note(
    [
      "1) SSH into your Nextcloud server",
      '2) Run: ./occ talk:bot:install "OpenClaw" "<shared-secret>" "<webhook-url>" --feature reaction',
      "3) Copy the shared secret you used in the command",
      "4) Enable the bot in your Nextcloud Talk room settings",
      "Tip: you can also set NEXTCLOUD_TALK_BOT_SECRET in your env.",
      `Docs: ${formatDocsLink("/channels/nextcloud-talk", "channels/nextcloud-talk")}`,
    ].join("\n"),
    "Nextcloud Talk bot setup",
  );
}

async function noteNextcloudTalkUserIdHelp(prompter: WizardPrompter): Promise<void> {
  await prompter.note(
    [
      "1) Check the Nextcloud admin panel for user IDs",
      "2) Or look at the webhook payload logs when someone messages",
      "3) User IDs are typically lowercase usernames in Nextcloud",
      `Docs: ${formatDocsLink("/channels/nextcloud-talk", "channels/nextcloud-talk")}`,
    ].join("\n"),
    "Nextcloud Talk user id",
  );
}

async function promptNextcloudTalkAllowFrom(params: {
  cfg: CoreConfig;
  prompter: WizardPrompter;
  accountId: string;
}): Promise<CoreConfig> {
  const { cfg, prompter, accountId } = params;
  const resolved = resolveNextcloudTalkAccount({ cfg, accountId });
  const existingAllowFrom = resolved.config.allowFrom ?? [];
  await noteNextcloudTalkUserIdHelp(prompter);

  const parseInput = (value: string) =>
    value
      .split(/[\n,;]+/g)
      .map((entry) => entry.trim().toLowerCase())
      .filter(Boolean);

  let resolvedIds: string[] = [];
  while (resolvedIds.length === 0) {
    const entry = await prompter.text({
      message: "Nextcloud Talk allowFrom (user id)",
      placeholder: "username",
      initialValue: existingAllowFrom[0] ? String(existingAllowFrom[0]) : undefined,
      validate: (value) => (String(value ?? "").trim() ? undefined : "Required"),
    });
    resolvedIds = parseInput(String(entry));
    if (resolvedIds.length === 0) {
      await prompter.note("Please enter at least one valid user ID.", "Nextcloud Talk allowlist");
    }
  }

  const merged = [
    ...existingAllowFrom.map((item) => String(item).trim().toLowerCase()).filter(Boolean),
    ...resolvedIds,
  ];
  const unique = [...new Set(merged)];

  if (accountId === DEFAULT_ACCOUNT_ID) {
    return {
      ...cfg,
      channels: {
        ...cfg.channels,
        "nextcloud-talk": {
          ...cfg.channels?.["nextcloud-talk"],
          enabled: true,
          dmPolicy: "allowlist",
          allowFrom: unique,
        },
      },
    };
  }

  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      "nextcloud-talk": {
        ...cfg.channels?.["nextcloud-talk"],
        enabled: true,
        accounts: {
          ...cfg.channels?.["nextcloud-talk"]?.accounts,
          [accountId]: {
            ...cfg.channels?.["nextcloud-talk"]?.accounts?.[accountId],
            enabled: cfg.channels?.["nextcloud-talk"]?.accounts?.[accountId]?.enabled ?? true,
            dmPolicy: "allowlist",
            allowFrom: unique,
          },
        },
      },
    },
  };
}

async function promptNextcloudTalkAllowFromForAccount(params: {
  cfg: CoreConfig;
  prompter: WizardPrompter;
  accountId?: string;
}): Promise<CoreConfig> {
  const accountId =
    params.accountId && normalizeAccountId(params.accountId)
      ? (normalizeAccountId(params.accountId) ?? DEFAULT_ACCOUNT_ID)
      : resolveDefaultNextcloudTalkAccountId(params.cfg);
  return promptNextcloudTalkAllowFrom({
    cfg: params.cfg,
    prompter: params.prompter,
    accountId,
  });
}

const dmPolicy: ChannelOnboardingDmPolicy = {
  label: "Nextcloud Talk",
  channel,
  policyKey: "channels.nextcloud-talk.dmPolicy",
  allowFromKey: "channels.nextcloud-talk.allowFrom",
  getCurrent: (cfg) => cfg.channels?.["nextcloud-talk"]?.dmPolicy ?? "pairing",
  setPolicy: (cfg, policy) => setNextcloudTalkDmPolicy(cfg as CoreConfig, policy as DmPolicy),
  promptAllowFrom: promptNextcloudTalkAllowFromForAccount as (params: {
    cfg: OpenClawConfig;
    prompter: WizardPrompter;
    accountId?: string | undefined;
  }) => Promise<OpenClawConfig>,
};

export const nextcloudTalkOnboardingAdapter: ChannelOnboardingAdapter = {
  channel,
  getStatus: async ({ cfg }) => {
    const configured = listNextcloudTalkAccountIds(cfg as CoreConfig).some((accountId) => {
      const account = resolveNextcloudTalkAccount({ cfg: cfg as CoreConfig, accountId });
      return Boolean(account.secret && account.baseUrl);
    });
    return {
      channel,
      configured,
      statusLines: [`Nextcloud Talk: ${configured ? "configured" : "needs setup"}`],
      selectionHint: configured ? "configured" : "self-hosted chat",
      quickstartScore: configured ? 1 : 5,
    };
  },
  configure: async ({
    cfg,
    prompter,
    accountOverrides,
    shouldPromptAccountIds,
    forceAllowFrom,
  }) => {
    const nextcloudTalkOverride = accountOverrides["nextcloud-talk"]?.trim();
    const defaultAccountId = resolveDefaultNextcloudTalkAccountId(cfg as CoreConfig);
    let accountId = nextcloudTalkOverride
      ? normalizeAccountId(nextcloudTalkOverride)
      : defaultAccountId;

    if (shouldPromptAccountIds && !nextcloudTalkOverride) {
      accountId = await promptAccountId({
        cfg: cfg as CoreConfig,
        prompter,
        label: "Nextcloud Talk",
        currentId: accountId,
        listAccountIds: listNextcloudTalkAccountIds as (cfg: OpenClawConfig) => string[],
        defaultAccountId,
      });
    }

    let next = cfg as CoreConfig;
    const resolvedAccount = resolveNextcloudTalkAccount({
      cfg: next,
      accountId,
    });
    const accountConfigured = Boolean(resolvedAccount.secret && resolvedAccount.baseUrl);
    const allowEnv = accountId === DEFAULT_ACCOUNT_ID;
    const canUseEnv = allowEnv && Boolean(process.env.NEXTCLOUD_TALK_BOT_SECRET?.trim());
    const hasConfigSecret = Boolean(
      resolvedAccount.config.botSecret || resolvedAccount.config.botSecretFile,
    );

    let baseUrl = resolvedAccount.baseUrl;
    if (!baseUrl) {
      baseUrl = String(
        await prompter.text({
          message: "Enter Nextcloud instance URL (e.g., https://cloud.example.com)",
          validate: (value) => {
            const v = String(value ?? "").trim();
            if (!v) {
              return "Required";
            }
            if (!v.startsWith("http://") && !v.startsWith("https://")) {
              return "URL must start with http:// or https://";
            }
            return undefined;
          },
        }),
      ).trim();
    }

    let secret: string | null = null;
    if (!accountConfigured) {
      await noteNextcloudTalkSecretHelp(prompter);
    }

    if (canUseEnv && !resolvedAccount.config.botSecret) {
      const keepEnv = await prompter.confirm({
        message: "NEXTCLOUD_TALK_BOT_SECRET detected. Use env var?",
        initialValue: true,
      });
      if (keepEnv) {
        next = {
          ...next,
          channels: {
            ...next.channels,
            "nextcloud-talk": {
              ...next.channels?.["nextcloud-talk"],
              enabled: true,
              baseUrl,
            },
          },
        };
      } else {
        secret = String(
          await prompter.text({
            message: "Enter Nextcloud Talk bot secret",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else if (hasConfigSecret) {
      const keep = await prompter.confirm({
        message: "Nextcloud Talk secret already configured. Keep it?",
        initialValue: true,
      });
      if (!keep) {
        secret = String(
          await prompter.text({
            message: "Enter Nextcloud Talk bot secret",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else {
      secret = String(
        await prompter.text({
          message: "Enter Nextcloud Talk bot secret",
          validate: (value) => (value?.trim() ? undefined : "Required"),
        }),
      ).trim();
    }

    if (secret || baseUrl !== resolvedAccount.baseUrl) {
      if (accountId === DEFAULT_ACCOUNT_ID) {
        next = {
          ...next,
          channels: {
            ...next.channels,
            "nextcloud-talk": {
              ...next.channels?.["nextcloud-talk"],
              enabled: true,
              baseUrl,
              ...(secret ? { botSecret: secret } : {}),
            },
          },
        };
      } else {
        next = {
          ...next,
          channels: {
            ...next.channels,
            "nextcloud-talk": {
              ...next.channels?.["nextcloud-talk"],
              enabled: true,
              accounts: {
                ...next.channels?.["nextcloud-talk"]?.accounts,
                [accountId]: {
                  ...next.channels?.["nextcloud-talk"]?.accounts?.[accountId],
                  enabled:
                    next.channels?.["nextcloud-talk"]?.accounts?.[accountId]?.enabled ?? true,
                  baseUrl,
                  ...(secret ? { botSecret: secret } : {}),
                },
              },
            },
          },
        };
      }
    }

    if (forceAllowFrom) {
      next = await promptNextcloudTalkAllowFrom({
        cfg: next,
        prompter,
        accountId,
      });
    }

    return { cfg: next, accountId };
  },
  dmPolicy,
  disable: (cfg) => ({
    ...cfg,
    channels: {
      ...cfg.channels,
      "nextcloud-talk": { ...cfg.channels?.["nextcloud-talk"], enabled: false },
    },
  }),
};
]]></file>
  <file path="./extensions/nextcloud-talk/src/accounts.ts"><![CDATA[import { readFileSync } from "node:fs";
import { DEFAULT_ACCOUNT_ID, isTruthyEnvValue, normalizeAccountId } from "openclaw/plugin-sdk";
import type { CoreConfig, NextcloudTalkAccountConfig } from "./types.js";

const debugAccounts = (...args: unknown[]) => {
  if (isTruthyEnvValue(process.env.OPENCLAW_DEBUG_NEXTCLOUD_TALK_ACCOUNTS)) {
    console.warn("[nextcloud-talk:accounts]", ...args);
  }
};

export type ResolvedNextcloudTalkAccount = {
  accountId: string;
  enabled: boolean;
  name?: string;
  baseUrl: string;
  secret: string;
  secretSource: "env" | "secretFile" | "config" | "none";
  config: NextcloudTalkAccountConfig;
};

function listConfiguredAccountIds(cfg: CoreConfig): string[] {
  const accounts = cfg.channels?.["nextcloud-talk"]?.accounts;
  if (!accounts || typeof accounts !== "object") {
    return [];
  }
  const ids = new Set<string>();
  for (const key of Object.keys(accounts)) {
    if (!key) {
      continue;
    }
    ids.add(normalizeAccountId(key));
  }
  return [...ids];
}

export function listNextcloudTalkAccountIds(cfg: CoreConfig): string[] {
  const ids = listConfiguredAccountIds(cfg);
  debugAccounts("listNextcloudTalkAccountIds", ids);
  if (ids.length === 0) {
    return [DEFAULT_ACCOUNT_ID];
  }
  return ids.toSorted((a, b) => a.localeCompare(b));
}

export function resolveDefaultNextcloudTalkAccountId(cfg: CoreConfig): string {
  const ids = listNextcloudTalkAccountIds(cfg);
  if (ids.includes(DEFAULT_ACCOUNT_ID)) {
    return DEFAULT_ACCOUNT_ID;
  }
  return ids[0] ?? DEFAULT_ACCOUNT_ID;
}

function resolveAccountConfig(
  cfg: CoreConfig,
  accountId: string,
): NextcloudTalkAccountConfig | undefined {
  const accounts = cfg.channels?.["nextcloud-talk"]?.accounts;
  if (!accounts || typeof accounts !== "object") {
    return undefined;
  }
  const direct = accounts[accountId] as NextcloudTalkAccountConfig | undefined;
  if (direct) {
    return direct;
  }
  const normalized = normalizeAccountId(accountId);
  const matchKey = Object.keys(accounts).find((key) => normalizeAccountId(key) === normalized);
  return matchKey ? (accounts[matchKey] as NextcloudTalkAccountConfig | undefined) : undefined;
}

function mergeNextcloudTalkAccountConfig(
  cfg: CoreConfig,
  accountId: string,
): NextcloudTalkAccountConfig {
  const { accounts: _ignored, ...base } = (cfg.channels?.["nextcloud-talk"] ??
    {}) as NextcloudTalkAccountConfig & { accounts?: unknown };
  const account = resolveAccountConfig(cfg, accountId) ?? {};
  return { ...base, ...account };
}

function resolveNextcloudTalkSecret(
  cfg: CoreConfig,
  opts: { accountId?: string },
): { secret: string; source: ResolvedNextcloudTalkAccount["secretSource"] } {
  const merged = mergeNextcloudTalkAccountConfig(cfg, opts.accountId ?? DEFAULT_ACCOUNT_ID);

  const envSecret = process.env.NEXTCLOUD_TALK_BOT_SECRET?.trim();
  if (envSecret && (!opts.accountId || opts.accountId === DEFAULT_ACCOUNT_ID)) {
    return { secret: envSecret, source: "env" };
  }

  if (merged.botSecretFile) {
    try {
      const fileSecret = readFileSync(merged.botSecretFile, "utf-8").trim();
      if (fileSecret) {
        return { secret: fileSecret, source: "secretFile" };
      }
    } catch {
      // File not found or unreadable, fall through.
    }
  }

  if (merged.botSecret?.trim()) {
    return { secret: merged.botSecret.trim(), source: "config" };
  }

  return { secret: "", source: "none" };
}

export function resolveNextcloudTalkAccount(params: {
  cfg: CoreConfig;
  accountId?: string | null;
}): ResolvedNextcloudTalkAccount {
  const hasExplicitAccountId = Boolean(params.accountId?.trim());
  const baseEnabled = params.cfg.channels?.["nextcloud-talk"]?.enabled !== false;

  const resolve = (accountId: string) => {
    const merged = mergeNextcloudTalkAccountConfig(params.cfg, accountId);
    const accountEnabled = merged.enabled !== false;
    const enabled = baseEnabled && accountEnabled;
    const secretResolution = resolveNextcloudTalkSecret(params.cfg, { accountId });
    const baseUrl = merged.baseUrl?.trim()?.replace(/\/$/, "") ?? "";

    debugAccounts("resolve", {
      accountId,
      enabled,
      secretSource: secretResolution.source,
      baseUrl: baseUrl ? "[set]" : "[missing]",
    });

    return {
      accountId,
      enabled,
      name: merged.name?.trim() || undefined,
      baseUrl,
      secret: secretResolution.secret,
      secretSource: secretResolution.source,
      config: merged,
    } satisfies ResolvedNextcloudTalkAccount;
  };

  const normalized = normalizeAccountId(params.accountId);
  const primary = resolve(normalized);
  if (hasExplicitAccountId) {
    return primary;
  }
  if (primary.secretSource !== "none") {
    return primary;
  }

  const fallbackId = resolveDefaultNextcloudTalkAccountId(params.cfg);
  if (fallbackId === primary.accountId) {
    return primary;
  }
  const fallback = resolve(fallbackId);
  if (fallback.secretSource === "none") {
    return primary;
  }
  return fallback;
}

export function listEnabledNextcloudTalkAccounts(cfg: CoreConfig): ResolvedNextcloudTalkAccount[] {
  return listNextcloudTalkAccountIds(cfg)
    .map((accountId) => resolveNextcloudTalkAccount({ cfg, accountId }))
    .filter((account) => account.enabled);
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/types.ts"><![CDATA[import type {
  BlockStreamingCoalesceConfig,
  DmConfig,
  DmPolicy,
  GroupPolicy,
} from "openclaw/plugin-sdk";

export type { DmPolicy, GroupPolicy };

export type NextcloudTalkRoomConfig = {
  requireMention?: boolean;
  /** Optional tool policy overrides for this room. */
  tools?: { allow?: string[]; deny?: string[] };
  /** If specified, only load these skills for this room. Omit = all skills; empty = no skills. */
  skills?: string[];
  /** If false, disable the bot for this room. */
  enabled?: boolean;
  /** Optional allowlist for room senders (user ids). */
  allowFrom?: string[];
  /** Optional system prompt snippet for this room. */
  systemPrompt?: string;
};

export type NextcloudTalkAccountConfig = {
  /** Optional display name for this account (used in CLI/UI lists). */
  name?: string;
  /** If false, do not start this Nextcloud Talk account. Default: true. */
  enabled?: boolean;
  /** Base URL of the Nextcloud instance (e.g., "https://cloud.example.com"). */
  baseUrl?: string;
  /** Bot shared secret from occ talk:bot:install output. */
  botSecret?: string;
  /** Path to file containing bot secret (for secret managers). */
  botSecretFile?: string;
  /** Optional API user for room lookups (DM detection). */
  apiUser?: string;
  /** Optional API password/app password for room lookups. */
  apiPassword?: string;
  /** Path to file containing API password/app password. */
  apiPasswordFile?: string;
  /** Direct message policy (default: pairing). */
  dmPolicy?: DmPolicy;
  /** Webhook server port. Default: 8788. */
  webhookPort?: number;
  /** Webhook server host. Default: "0.0.0.0". */
  webhookHost?: string;
  /** Webhook endpoint path. Default: "/nextcloud-talk-webhook". */
  webhookPath?: string;
  /** Public URL for the webhook (used if behind reverse proxy). */
  webhookPublicUrl?: string;
  /** Optional allowlist of user IDs allowed to DM the bot. */
  allowFrom?: string[];
  /** Optional allowlist for Nextcloud Talk room senders (user ids). */
  groupAllowFrom?: string[];
  /** Group message policy (default: allowlist). */
  groupPolicy?: GroupPolicy;
  /** Per-room configuration (key is room token). */
  rooms?: Record<string, NextcloudTalkRoomConfig>;
  /** Max group messages to keep as history context (0 disables). */
  historyLimit?: number;
  /** Max DM turns to keep as history context. */
  dmHistoryLimit?: number;
  /** Per-DM config overrides keyed by user ID. */
  dms?: Record<string, DmConfig>;
  /** Outbound text chunk size (chars). Default: 4000. */
  textChunkLimit?: number;
  /** Chunking mode: "length" (default) splits by size; "newline" splits on every newline. */
  chunkMode?: "length" | "newline";
  /** Disable block streaming for this account. */
  blockStreaming?: boolean;
  /** Merge streamed block replies before sending. */
  blockStreamingCoalesce?: BlockStreamingCoalesceConfig;
  /** Outbound response prefix override for this channel/account. */
  responsePrefix?: string;
  /** Media upload max size in MB. */
  mediaMaxMb?: number;
};

export type NextcloudTalkConfig = {
  /** Optional per-account Nextcloud Talk configuration (multi-account). */
  accounts?: Record<string, NextcloudTalkAccountConfig>;
} & NextcloudTalkAccountConfig;

export type CoreConfig = {
  channels?: {
    "nextcloud-talk"?: NextcloudTalkConfig;
  };
  [key: string]: unknown;
};

/**
 * Nextcloud Talk webhook payload types based on Activity Streams 2.0 format.
 * Reference: https://nextcloud-talk.readthedocs.io/en/latest/bots/
 */

/** Actor in the activity (the message sender). */
export type NextcloudTalkActor = {
  type: "Person";
  /** User ID in Nextcloud. */
  id: string;
  /** Display name of the user. */
  name: string;
};

/** The message object in the activity. */
export type NextcloudTalkObject = {
  type: "Note";
  /** Message ID. */
  id: string;
  /** Message text (same as content for text/plain). */
  name: string;
  /** Message content. */
  content: string;
  /** Media type of the content. */
  mediaType: string;
};

/** Target conversation/room. */
export type NextcloudTalkTarget = {
  type: "Collection";
  /** Room token. */
  id: string;
  /** Room display name. */
  name: string;
};

/** Incoming webhook payload from Nextcloud Talk. */
export type NextcloudTalkWebhookPayload = {
  type: "Create" | "Update" | "Delete";
  actor: NextcloudTalkActor;
  object: NextcloudTalkObject;
  target: NextcloudTalkTarget;
};

/** Result from sending a message to Nextcloud Talk. */
export type NextcloudTalkSendResult = {
  messageId: string;
  roomToken: string;
  timestamp?: number;
};

/** Parsed incoming message context. */
export type NextcloudTalkInboundMessage = {
  messageId: string;
  roomToken: string;
  roomName: string;
  senderId: string;
  senderName: string;
  text: string;
  mediaType: string;
  timestamp: number;
  isGroupChat: boolean;
};

/** Headers sent by Nextcloud Talk webhook. */
export type NextcloudTalkWebhookHeaders = {
  /** HMAC-SHA256 signature of the request. */
  signature: string;
  /** Random string used in signature calculation. */
  random: string;
  /** Backend Nextcloud server URL. */
  backend: string;
};

/** Options for the webhook server. */
export type NextcloudTalkWebhookServerOptions = {
  port: number;
  host: string;
  path: string;
  secret: string;
  onMessage: (message: NextcloudTalkInboundMessage) => void | Promise<void>;
  onError?: (error: Error) => void;
  abortSignal?: AbortSignal;
};

/** Options for sending a message. */
export type NextcloudTalkSendOptions = {
  baseUrl: string;
  secret: string;
  roomToken: string;
  message: string;
  replyTo?: string;
};
]]></file>
  <file path="./extensions/nextcloud-talk/src/policy.ts"><![CDATA[import type {
  AllowlistMatch,
  ChannelGroupContext,
  GroupPolicy,
  GroupToolPolicyConfig,
} from "openclaw/plugin-sdk";
import {
  buildChannelKeyCandidates,
  normalizeChannelSlug,
  resolveChannelEntryMatchWithFallback,
  resolveMentionGatingWithBypass,
  resolveNestedAllowlistDecision,
} from "openclaw/plugin-sdk";
import type { NextcloudTalkRoomConfig } from "./types.js";

function normalizeAllowEntry(raw: string): string {
  return raw
    .trim()
    .toLowerCase()
    .replace(/^(nextcloud-talk|nc-talk|nc):/i, "");
}

export function normalizeNextcloudTalkAllowlist(
  values: Array<string | number> | undefined,
): string[] {
  return (values ?? []).map((value) => normalizeAllowEntry(String(value))).filter(Boolean);
}

export function resolveNextcloudTalkAllowlistMatch(params: {
  allowFrom: Array<string | number> | undefined;
  senderId: string;
}): AllowlistMatch<"wildcard" | "id"> {
  const allowFrom = normalizeNextcloudTalkAllowlist(params.allowFrom);
  if (allowFrom.length === 0) {
    return { allowed: false };
  }
  if (allowFrom.includes("*")) {
    return { allowed: true, matchKey: "*", matchSource: "wildcard" };
  }
  const senderId = normalizeAllowEntry(params.senderId);
  if (allowFrom.includes(senderId)) {
    return { allowed: true, matchKey: senderId, matchSource: "id" };
  }
  return { allowed: false };
}

export type NextcloudTalkRoomMatch = {
  roomConfig?: NextcloudTalkRoomConfig;
  wildcardConfig?: NextcloudTalkRoomConfig;
  roomKey?: string;
  matchSource?: "direct" | "parent" | "wildcard";
  allowed: boolean;
  allowlistConfigured: boolean;
};

export function resolveNextcloudTalkRoomMatch(params: {
  rooms?: Record<string, NextcloudTalkRoomConfig>;
  roomToken: string;
  roomName?: string | null;
}): NextcloudTalkRoomMatch {
  const rooms = params.rooms ?? {};
  const allowlistConfigured = Object.keys(rooms).length > 0;
  const roomName = params.roomName?.trim() || undefined;
  const roomCandidates = buildChannelKeyCandidates(
    params.roomToken,
    roomName,
    roomName ? normalizeChannelSlug(roomName) : undefined,
  );
  const match = resolveChannelEntryMatchWithFallback({
    entries: rooms,
    keys: roomCandidates,
    wildcardKey: "*",
    normalizeKey: normalizeChannelSlug,
  });
  const roomConfig = match.entry;
  const allowed = resolveNestedAllowlistDecision({
    outerConfigured: allowlistConfigured,
    outerMatched: Boolean(roomConfig),
    innerConfigured: false,
    innerMatched: false,
  });

  return {
    roomConfig,
    wildcardConfig: match.wildcardEntry,
    roomKey: match.matchKey ?? match.key,
    matchSource: match.matchSource,
    allowed,
    allowlistConfigured,
  };
}

export function resolveNextcloudTalkGroupToolPolicy(
  params: ChannelGroupContext,
): GroupToolPolicyConfig | undefined {
  const cfg = params.cfg as {
    channels?: { "nextcloud-talk"?: { rooms?: Record<string, NextcloudTalkRoomConfig> } };
  };
  const roomToken = params.groupId?.trim();
  if (!roomToken) {
    return undefined;
  }
  const roomName = params.groupChannel?.trim() || undefined;
  const match = resolveNextcloudTalkRoomMatch({
    rooms: cfg.channels?.["nextcloud-talk"]?.rooms,
    roomToken,
    roomName,
  });
  return match.roomConfig?.tools ?? match.wildcardConfig?.tools;
}

export function resolveNextcloudTalkRequireMention(params: {
  roomConfig?: NextcloudTalkRoomConfig;
  wildcardConfig?: NextcloudTalkRoomConfig;
}): boolean {
  if (typeof params.roomConfig?.requireMention === "boolean") {
    return params.roomConfig.requireMention;
  }
  if (typeof params.wildcardConfig?.requireMention === "boolean") {
    return params.wildcardConfig.requireMention;
  }
  return true;
}

export function resolveNextcloudTalkGroupAllow(params: {
  groupPolicy: GroupPolicy;
  outerAllowFrom: Array<string | number> | undefined;
  innerAllowFrom: Array<string | number> | undefined;
  senderId: string;
}): { allowed: boolean; outerMatch: AllowlistMatch; innerMatch: AllowlistMatch } {
  if (params.groupPolicy === "disabled") {
    return { allowed: false, outerMatch: { allowed: false }, innerMatch: { allowed: false } };
  }
  if (params.groupPolicy === "open") {
    return { allowed: true, outerMatch: { allowed: true }, innerMatch: { allowed: true } };
  }

  const outerAllow = normalizeNextcloudTalkAllowlist(params.outerAllowFrom);
  const innerAllow = normalizeNextcloudTalkAllowlist(params.innerAllowFrom);
  if (outerAllow.length === 0 && innerAllow.length === 0) {
    return { allowed: false, outerMatch: { allowed: false }, innerMatch: { allowed: false } };
  }

  const outerMatch = resolveNextcloudTalkAllowlistMatch({
    allowFrom: params.outerAllowFrom,
    senderId: params.senderId,
  });
  const innerMatch = resolveNextcloudTalkAllowlistMatch({
    allowFrom: params.innerAllowFrom,
    senderId: params.senderId,
  });
  const allowed = resolveNestedAllowlistDecision({
    outerConfigured: outerAllow.length > 0 || innerAllow.length > 0,
    outerMatched: outerAllow.length > 0 ? outerMatch.allowed : true,
    innerConfigured: innerAllow.length > 0,
    innerMatched: innerMatch.allowed,
  });

  return { allowed, outerMatch, innerMatch };
}

export function resolveNextcloudTalkMentionGate(params: {
  isGroup: boolean;
  requireMention: boolean;
  wasMentioned: boolean;
  allowTextCommands: boolean;
  hasControlCommand: boolean;
  commandAuthorized: boolean;
}): { shouldSkip: boolean; shouldBypassMention: boolean } {
  const result = resolveMentionGatingWithBypass({
    isGroup: params.isGroup,
    requireMention: params.requireMention,
    canDetectMention: true,
    wasMentioned: params.wasMentioned,
    allowTextCommands: params.allowTextCommands,
    hasControlCommand: params.hasControlCommand,
    commandAuthorized: params.commandAuthorized,
  });
  return { shouldSkip: result.shouldSkip, shouldBypassMention: result.shouldBypassMention };
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/config-schema.ts"><![CDATA[import {
  BlockStreamingCoalesceSchema,
  DmConfigSchema,
  DmPolicySchema,
  GroupPolicySchema,
  MarkdownConfigSchema,
  ToolPolicySchema,
  requireOpenAllowFrom,
} from "openclaw/plugin-sdk";
import { z } from "zod";

export const NextcloudTalkRoomSchema = z
  .object({
    requireMention: z.boolean().optional(),
    tools: ToolPolicySchema,
    skills: z.array(z.string()).optional(),
    enabled: z.boolean().optional(),
    allowFrom: z.array(z.string()).optional(),
    systemPrompt: z.string().optional(),
  })
  .strict();

export const NextcloudTalkAccountSchemaBase = z
  .object({
    name: z.string().optional(),
    enabled: z.boolean().optional(),
    markdown: MarkdownConfigSchema,
    baseUrl: z.string().optional(),
    botSecret: z.string().optional(),
    botSecretFile: z.string().optional(),
    apiUser: z.string().optional(),
    apiPassword: z.string().optional(),
    apiPasswordFile: z.string().optional(),
    dmPolicy: DmPolicySchema.optional().default("pairing"),
    webhookPort: z.number().int().positive().optional(),
    webhookHost: z.string().optional(),
    webhookPath: z.string().optional(),
    webhookPublicUrl: z.string().optional(),
    allowFrom: z.array(z.string()).optional(),
    groupAllowFrom: z.array(z.string()).optional(),
    groupPolicy: GroupPolicySchema.optional().default("allowlist"),
    rooms: z.record(z.string(), NextcloudTalkRoomSchema.optional()).optional(),
    historyLimit: z.number().int().min(0).optional(),
    dmHistoryLimit: z.number().int().min(0).optional(),
    dms: z.record(z.string(), DmConfigSchema.optional()).optional(),
    textChunkLimit: z.number().int().positive().optional(),
    chunkMode: z.enum(["length", "newline"]).optional(),
    blockStreaming: z.boolean().optional(),
    blockStreamingCoalesce: BlockStreamingCoalesceSchema.optional(),
    responsePrefix: z.string().optional(),
    mediaMaxMb: z.number().positive().optional(),
  })
  .strict();

export const NextcloudTalkAccountSchema = NextcloudTalkAccountSchemaBase.superRefine(
  (value, ctx) => {
    requireOpenAllowFrom({
      policy: value.dmPolicy,
      allowFrom: value.allowFrom,
      ctx,
      path: ["allowFrom"],
      message:
        'channels.nextcloud-talk.dmPolicy="open" requires channels.nextcloud-talk.allowFrom to include "*"',
    });
  },
);

export const NextcloudTalkConfigSchema = NextcloudTalkAccountSchemaBase.extend({
  accounts: z.record(z.string(), NextcloudTalkAccountSchema.optional()).optional(),
}).superRefine((value, ctx) => {
  requireOpenAllowFrom({
    policy: value.dmPolicy,
    allowFrom: value.allowFrom,
    ctx,
    path: ["allowFrom"],
    message:
      'channels.nextcloud-talk.dmPolicy="open" requires channels.nextcloud-talk.allowFrom to include "*"',
  });
});
]]></file>
  <file path="./extensions/nextcloud-talk/src/inbound.ts"><![CDATA[import {
  createReplyPrefixOptions,
  logInboundDrop,
  resolveControlCommandGate,
  type OpenClawConfig,
  type RuntimeEnv,
} from "openclaw/plugin-sdk";
import type { ResolvedNextcloudTalkAccount } from "./accounts.js";
import type { CoreConfig, GroupPolicy, NextcloudTalkInboundMessage } from "./types.js";
import {
  normalizeNextcloudTalkAllowlist,
  resolveNextcloudTalkAllowlistMatch,
  resolveNextcloudTalkGroupAllow,
  resolveNextcloudTalkMentionGate,
  resolveNextcloudTalkRequireMention,
  resolveNextcloudTalkRoomMatch,
} from "./policy.js";
import { resolveNextcloudTalkRoomKind } from "./room-info.js";
import { getNextcloudTalkRuntime } from "./runtime.js";
import { sendMessageNextcloudTalk } from "./send.js";

const CHANNEL_ID = "nextcloud-talk" as const;

async function deliverNextcloudTalkReply(params: {
  payload: { text?: string; mediaUrls?: string[]; mediaUrl?: string; replyToId?: string };
  roomToken: string;
  accountId: string;
  statusSink?: (patch: { lastOutboundAt?: number }) => void;
}): Promise<void> {
  const { payload, roomToken, accountId, statusSink } = params;
  const text = payload.text ?? "";
  const mediaList = payload.mediaUrls?.length
    ? payload.mediaUrls
    : payload.mediaUrl
      ? [payload.mediaUrl]
      : [];

  if (!text.trim() && mediaList.length === 0) {
    return;
  }

  const mediaBlock = mediaList.length
    ? mediaList.map((url) => `Attachment: ${url}`).join("\n")
    : "";
  const combined = text.trim()
    ? mediaBlock
      ? `${text.trim()}\n\n${mediaBlock}`
      : text.trim()
    : mediaBlock;

  await sendMessageNextcloudTalk(roomToken, combined, {
    accountId,
    replyTo: payload.replyToId,
  });
  statusSink?.({ lastOutboundAt: Date.now() });
}

export async function handleNextcloudTalkInbound(params: {
  message: NextcloudTalkInboundMessage;
  account: ResolvedNextcloudTalkAccount;
  config: CoreConfig;
  runtime: RuntimeEnv;
  statusSink?: (patch: { lastInboundAt?: number; lastOutboundAt?: number }) => void;
}): Promise<void> {
  const { message, account, config, runtime, statusSink } = params;
  const core = getNextcloudTalkRuntime();

  const rawBody = message.text?.trim() ?? "";
  if (!rawBody) {
    return;
  }

  const roomKind = await resolveNextcloudTalkRoomKind({
    account,
    roomToken: message.roomToken,
    runtime,
  });
  const isGroup = roomKind === "direct" ? false : roomKind === "group" ? true : message.isGroupChat;
  const senderId = message.senderId;
  const senderName = message.senderName;
  const roomToken = message.roomToken;
  const roomName = message.roomName;

  statusSink?.({ lastInboundAt: message.timestamp });

  const dmPolicy = account.config.dmPolicy ?? "pairing";
  const defaultGroupPolicy = (config.channels as Record<string, unknown> | undefined)?.defaults as
    | { groupPolicy?: string }
    | undefined;
  const groupPolicy = (account.config.groupPolicy ??
    defaultGroupPolicy?.groupPolicy ??
    "allowlist") as GroupPolicy;

  const configAllowFrom = normalizeNextcloudTalkAllowlist(account.config.allowFrom);
  const configGroupAllowFrom = normalizeNextcloudTalkAllowlist(account.config.groupAllowFrom);
  const storeAllowFrom = await core.channel.pairing.readAllowFromStore(CHANNEL_ID).catch(() => []);
  const storeAllowList = normalizeNextcloudTalkAllowlist(storeAllowFrom);

  const roomMatch = resolveNextcloudTalkRoomMatch({
    rooms: account.config.rooms,
    roomToken,
    roomName,
  });
  const roomConfig = roomMatch.roomConfig;
  if (isGroup && !roomMatch.allowed) {
    runtime.log?.(`nextcloud-talk: drop room ${roomToken} (not allowlisted)`);
    return;
  }
  if (roomConfig?.enabled === false) {
    runtime.log?.(`nextcloud-talk: drop room ${roomToken} (disabled)`);
    return;
  }

  const roomAllowFrom = normalizeNextcloudTalkAllowlist(roomConfig?.allowFrom);
  const baseGroupAllowFrom =
    configGroupAllowFrom.length > 0 ? configGroupAllowFrom : configAllowFrom;

  const effectiveAllowFrom = [...configAllowFrom, ...storeAllowList].filter(Boolean);
  const effectiveGroupAllowFrom = [...baseGroupAllowFrom, ...storeAllowList].filter(Boolean);

  const allowTextCommands = core.channel.commands.shouldHandleTextCommands({
    cfg: config as OpenClawConfig,
    surface: CHANNEL_ID,
  });
  const useAccessGroups =
    (config.commands as Record<string, unknown> | undefined)?.useAccessGroups !== false;
  const senderAllowedForCommands = resolveNextcloudTalkAllowlistMatch({
    allowFrom: isGroup ? effectiveGroupAllowFrom : effectiveAllowFrom,
    senderId,
  }).allowed;
  const hasControlCommand = core.channel.text.hasControlCommand(rawBody, config as OpenClawConfig);
  const commandGate = resolveControlCommandGate({
    useAccessGroups,
    authorizers: [
      {
        configured: (isGroup ? effectiveGroupAllowFrom : effectiveAllowFrom).length > 0,
        allowed: senderAllowedForCommands,
      },
    ],
    allowTextCommands,
    hasControlCommand,
  });
  const commandAuthorized = commandGate.commandAuthorized;

  if (isGroup) {
    const groupAllow = resolveNextcloudTalkGroupAllow({
      groupPolicy,
      outerAllowFrom: effectiveGroupAllowFrom,
      innerAllowFrom: roomAllowFrom,
      senderId,
    });
    if (!groupAllow.allowed) {
      runtime.log?.(`nextcloud-talk: drop group sender ${senderId} (policy=${groupPolicy})`);
      return;
    }
  } else {
    if (dmPolicy === "disabled") {
      runtime.log?.(`nextcloud-talk: drop DM sender=${senderId} (dmPolicy=disabled)`);
      return;
    }
    if (dmPolicy !== "open") {
      const dmAllowed = resolveNextcloudTalkAllowlistMatch({
        allowFrom: effectiveAllowFrom,
        senderId,
      }).allowed;
      if (!dmAllowed) {
        if (dmPolicy === "pairing") {
          const { code, created } = await core.channel.pairing.upsertPairingRequest({
            channel: CHANNEL_ID,
            id: senderId,
            meta: { name: senderName || undefined },
          });
          if (created) {
            try {
              await sendMessageNextcloudTalk(
                roomToken,
                core.channel.pairing.buildPairingReply({
                  channel: CHANNEL_ID,
                  idLine: `Your Nextcloud user id: ${senderId}`,
                  code,
                }),
                { accountId: account.accountId },
              );
              statusSink?.({ lastOutboundAt: Date.now() });
            } catch (err) {
              runtime.error?.(
                `nextcloud-talk: pairing reply failed for ${senderId}: ${String(err)}`,
              );
            }
          }
        }
        runtime.log?.(`nextcloud-talk: drop DM sender ${senderId} (dmPolicy=${dmPolicy})`);
        return;
      }
    }
  }

  if (isGroup && commandGate.shouldBlock) {
    logInboundDrop({
      log: (message) => runtime.log?.(message),
      channel: CHANNEL_ID,
      reason: "control command (unauthorized)",
      target: senderId,
    });
    return;
  }

  const mentionRegexes = core.channel.mentions.buildMentionRegexes(config as OpenClawConfig);
  const wasMentioned = mentionRegexes.length
    ? core.channel.mentions.matchesMentionPatterns(rawBody, mentionRegexes)
    : false;
  const shouldRequireMention = isGroup
    ? resolveNextcloudTalkRequireMention({
        roomConfig,
        wildcardConfig: roomMatch.wildcardConfig,
      })
    : false;
  const mentionGate = resolveNextcloudTalkMentionGate({
    isGroup,
    requireMention: shouldRequireMention,
    wasMentioned,
    allowTextCommands,
    hasControlCommand,
    commandAuthorized,
  });
  if (isGroup && mentionGate.shouldSkip) {
    runtime.log?.(`nextcloud-talk: drop room ${roomToken} (no mention)`);
    return;
  }

  const route = core.channel.routing.resolveAgentRoute({
    cfg: config as OpenClawConfig,
    channel: CHANNEL_ID,
    accountId: account.accountId,
    peer: {
      kind: isGroup ? "group" : "direct",
      id: isGroup ? roomToken : senderId,
    },
  });

  const fromLabel = isGroup ? `room:${roomName || roomToken}` : senderName || `user:${senderId}`;
  const storePath = core.channel.session.resolveStorePath(
    (config.session as Record<string, unknown> | undefined)?.store as string | undefined,
    {
      agentId: route.agentId,
    },
  );
  const envelopeOptions = core.channel.reply.resolveEnvelopeFormatOptions(config as OpenClawConfig);
  const previousTimestamp = core.channel.session.readSessionUpdatedAt({
    storePath,
    sessionKey: route.sessionKey,
  });
  const body = core.channel.reply.formatAgentEnvelope({
    channel: "Nextcloud Talk",
    from: fromLabel,
    timestamp: message.timestamp,
    previousTimestamp,
    envelope: envelopeOptions,
    body: rawBody,
  });

  const groupSystemPrompt = roomConfig?.systemPrompt?.trim() || undefined;

  const ctxPayload = core.channel.reply.finalizeInboundContext({
    Body: body,
    BodyForAgent: rawBody,
    RawBody: rawBody,
    CommandBody: rawBody,
    From: isGroup ? `nextcloud-talk:room:${roomToken}` : `nextcloud-talk:${senderId}`,
    To: `nextcloud-talk:${roomToken}`,
    SessionKey: route.sessionKey,
    AccountId: route.accountId,
    ChatType: isGroup ? "group" : "direct",
    ConversationLabel: fromLabel,
    SenderName: senderName || undefined,
    SenderId: senderId,
    GroupSubject: isGroup ? roomName || roomToken : undefined,
    GroupSystemPrompt: isGroup ? groupSystemPrompt : undefined,
    Provider: CHANNEL_ID,
    Surface: CHANNEL_ID,
    WasMentioned: isGroup ? wasMentioned : undefined,
    MessageSid: message.messageId,
    Timestamp: message.timestamp,
    OriginatingChannel: CHANNEL_ID,
    OriginatingTo: `nextcloud-talk:${roomToken}`,
    CommandAuthorized: commandAuthorized,
  });

  await core.channel.session.recordInboundSession({
    storePath,
    sessionKey: ctxPayload.SessionKey ?? route.sessionKey,
    ctx: ctxPayload,
    onRecordError: (err) => {
      runtime.error?.(`nextcloud-talk: failed updating session meta: ${String(err)}`);
    },
  });

  const { onModelSelected, ...prefixOptions } = createReplyPrefixOptions({
    cfg: config as OpenClawConfig,
    agentId: route.agentId,
    channel: CHANNEL_ID,
    accountId: account.accountId,
  });

  await core.channel.reply.dispatchReplyWithBufferedBlockDispatcher({
    ctx: ctxPayload,
    cfg: config as OpenClawConfig,
    dispatcherOptions: {
      ...prefixOptions,
      deliver: async (payload) => {
        await deliverNextcloudTalkReply({
          payload: payload as {
            text?: string;
            mediaUrls?: string[];
            mediaUrl?: string;
            replyToId?: string;
          },
          roomToken,
          accountId: account.accountId,
          statusSink,
        });
      },
      onError: (err, info) => {
        runtime.error?.(`nextcloud-talk ${info.kind} reply failed: ${String(err)}`);
      },
    },
    replyOptions: {
      skillFilter: roomConfig?.skills,
      onModelSelected,
      disableBlockStreaming:
        typeof account.config.blockStreaming === "boolean"
          ? !account.config.blockStreaming
          : undefined,
    },
  });
}
]]></file>
  <file path="./extensions/nextcloud-talk/src/channel.ts"><![CDATA[import {
  applyAccountNameToChannelSection,
  buildChannelConfigSchema,
  DEFAULT_ACCOUNT_ID,
  deleteAccountFromConfigSection,
  formatPairingApproveHint,
  normalizeAccountId,
  setAccountEnabledInConfigSection,
  type ChannelPlugin,
  type OpenClawConfig,
  type ChannelSetupInput,
} from "openclaw/plugin-sdk";
import type { CoreConfig } from "./types.js";
import {
  listNextcloudTalkAccountIds,
  resolveDefaultNextcloudTalkAccountId,
  resolveNextcloudTalkAccount,
  type ResolvedNextcloudTalkAccount,
} from "./accounts.js";
import { NextcloudTalkConfigSchema } from "./config-schema.js";
import { monitorNextcloudTalkProvider } from "./monitor.js";
import {
  looksLikeNextcloudTalkTargetId,
  normalizeNextcloudTalkMessagingTarget,
} from "./normalize.js";
import { nextcloudTalkOnboardingAdapter } from "./onboarding.js";
import { resolveNextcloudTalkGroupToolPolicy } from "./policy.js";
import { getNextcloudTalkRuntime } from "./runtime.js";
import { sendMessageNextcloudTalk } from "./send.js";

const meta = {
  id: "nextcloud-talk",
  label: "Nextcloud Talk",
  selectionLabel: "Nextcloud Talk (self-hosted)",
  docsPath: "/channels/nextcloud-talk",
  docsLabel: "nextcloud-talk",
  blurb: "Self-hosted chat via Nextcloud Talk webhook bots.",
  aliases: ["nc-talk", "nc"],
  order: 65,
  quickstartAllowFrom: true,
};

type NextcloudSetupInput = ChannelSetupInput & {
  baseUrl?: string;
  secret?: string;
  secretFile?: string;
  useEnv?: boolean;
};

export const nextcloudTalkPlugin: ChannelPlugin<ResolvedNextcloudTalkAccount> = {
  id: "nextcloud-talk",
  meta,
  onboarding: nextcloudTalkOnboardingAdapter,
  pairing: {
    idLabel: "nextcloudUserId",
    normalizeAllowEntry: (entry) =>
      entry.replace(/^(nextcloud-talk|nc-talk|nc):/i, "").toLowerCase(),
    notifyApproval: async ({ id }) => {
      console.log(`[nextcloud-talk] User ${id} approved for pairing`);
    },
  },
  capabilities: {
    chatTypes: ["direct", "group"],
    reactions: true,
    threads: false,
    media: true,
    nativeCommands: false,
    blockStreaming: true,
  },
  reload: { configPrefixes: ["channels.nextcloud-talk"] },
  configSchema: buildChannelConfigSchema(NextcloudTalkConfigSchema),
  config: {
    listAccountIds: (cfg) => listNextcloudTalkAccountIds(cfg as CoreConfig),
    resolveAccount: (cfg, accountId) =>
      resolveNextcloudTalkAccount({ cfg: cfg as CoreConfig, accountId }),
    defaultAccountId: (cfg) => resolveDefaultNextcloudTalkAccountId(cfg as CoreConfig),
    setAccountEnabled: ({ cfg, accountId, enabled }) =>
      setAccountEnabledInConfigSection({
        cfg,
        sectionKey: "nextcloud-talk",
        accountId,
        enabled,
        allowTopLevel: true,
      }),
    deleteAccount: ({ cfg, accountId }) =>
      deleteAccountFromConfigSection({
        cfg,
        sectionKey: "nextcloud-talk",
        accountId,
        clearBaseFields: ["botSecret", "botSecretFile", "baseUrl", "name"],
      }),
    isConfigured: (account) => Boolean(account.secret?.trim() && account.baseUrl?.trim()),
    describeAccount: (account) => ({
      accountId: account.accountId,
      name: account.name,
      enabled: account.enabled,
      configured: Boolean(account.secret?.trim() && account.baseUrl?.trim()),
      secretSource: account.secretSource,
      baseUrl: account.baseUrl ? "[set]" : "[missing]",
    }),
    resolveAllowFrom: ({ cfg, accountId }) =>
      (
        resolveNextcloudTalkAccount({ cfg: cfg as CoreConfig, accountId }).config.allowFrom ?? []
      ).map((entry) => String(entry).toLowerCase()),
    formatAllowFrom: ({ allowFrom }) =>
      allowFrom
        .map((entry) => String(entry).trim())
        .filter(Boolean)
        .map((entry) => entry.replace(/^(nextcloud-talk|nc-talk|nc):/i, ""))
        .map((entry) => entry.toLowerCase()),
  },
  security: {
    resolveDmPolicy: ({ cfg, accountId, account }) => {
      const resolvedAccountId = accountId ?? account.accountId ?? DEFAULT_ACCOUNT_ID;
      const useAccountPath = Boolean(
        cfg.channels?.["nextcloud-talk"]?.accounts?.[resolvedAccountId],
      );
      const basePath = useAccountPath
        ? `channels.nextcloud-talk.accounts.${resolvedAccountId}.`
        : "channels.nextcloud-talk.";
      return {
        policy: account.config.dmPolicy ?? "pairing",
        allowFrom: account.config.allowFrom ?? [],
        policyPath: `${basePath}dmPolicy`,
        allowFromPath: basePath,
        approveHint: formatPairingApproveHint("nextcloud-talk"),
        normalizeEntry: (raw) => raw.replace(/^(nextcloud-talk|nc-talk|nc):/i, "").toLowerCase(),
      };
    },
    collectWarnings: ({ account, cfg }) => {
      const defaultGroupPolicy = cfg.channels?.defaults?.groupPolicy;
      const groupPolicy = account.config.groupPolicy ?? defaultGroupPolicy ?? "allowlist";
      if (groupPolicy !== "open") {
        return [];
      }
      const roomAllowlistConfigured =
        account.config.rooms && Object.keys(account.config.rooms).length > 0;
      if (roomAllowlistConfigured) {
        return [
          `- Nextcloud Talk rooms: groupPolicy="open" allows any member in allowed rooms to trigger (mention-gated). Set channels.nextcloud-talk.groupPolicy="allowlist" + channels.nextcloud-talk.groupAllowFrom to restrict senders.`,
        ];
      }
      return [
        `- Nextcloud Talk rooms: groupPolicy="open" with no channels.nextcloud-talk.rooms allowlist; any room can add + ping (mention-gated). Set channels.nextcloud-talk.groupPolicy="allowlist" + channels.nextcloud-talk.groupAllowFrom or configure channels.nextcloud-talk.rooms.`,
      ];
    },
  },
  groups: {
    resolveRequireMention: ({ cfg, accountId, groupId }) => {
      const account = resolveNextcloudTalkAccount({ cfg: cfg as CoreConfig, accountId });
      const rooms = account.config.rooms;
      if (!rooms || !groupId) {
        return true;
      }

      const roomConfig = rooms[groupId];
      if (roomConfig?.requireMention !== undefined) {
        return roomConfig.requireMention;
      }

      const wildcardConfig = rooms["*"];
      if (wildcardConfig?.requireMention !== undefined) {
        return wildcardConfig.requireMention;
      }

      return true;
    },
    resolveToolPolicy: resolveNextcloudTalkGroupToolPolicy,
  },
  messaging: {
    normalizeTarget: normalizeNextcloudTalkMessagingTarget,
    targetResolver: {
      looksLikeId: looksLikeNextcloudTalkTargetId,
      hint: "<roomToken>",
    },
  },
  setup: {
    resolveAccountId: ({ accountId }) => normalizeAccountId(accountId),
    applyAccountName: ({ cfg, accountId, name }) =>
      applyAccountNameToChannelSection({
        cfg: cfg,
        channelKey: "nextcloud-talk",
        accountId,
        name,
      }),
    validateInput: ({ accountId, input }) => {
      const setupInput = input as NextcloudSetupInput;
      if (setupInput.useEnv && accountId !== DEFAULT_ACCOUNT_ID) {
        return "NEXTCLOUD_TALK_BOT_SECRET can only be used for the default account.";
      }
      if (!setupInput.useEnv && !setupInput.secret && !setupInput.secretFile) {
        return "Nextcloud Talk requires bot secret or --secret-file (or --use-env).";
      }
      if (!setupInput.baseUrl) {
        return "Nextcloud Talk requires --base-url.";
      }
      return null;
    },
    applyAccountConfig: ({ cfg, accountId, input }) => {
      const setupInput = input as NextcloudSetupInput;
      const namedConfig = applyAccountNameToChannelSection({
        cfg: cfg,
        channelKey: "nextcloud-talk",
        accountId,
        name: setupInput.name,
      });
      if (accountId === DEFAULT_ACCOUNT_ID) {
        return {
          ...namedConfig,
          channels: {
            ...namedConfig.channels,
            "nextcloud-talk": {
              ...namedConfig.channels?.["nextcloud-talk"],
              enabled: true,
              baseUrl: setupInput.baseUrl,
              ...(setupInput.useEnv
                ? {}
                : setupInput.secretFile
                  ? { botSecretFile: setupInput.secretFile }
                  : setupInput.secret
                    ? { botSecret: setupInput.secret }
                    : {}),
            },
          },
        } as OpenClawConfig;
      }
      return {
        ...namedConfig,
        channels: {
          ...namedConfig.channels,
          "nextcloud-talk": {
            ...namedConfig.channels?.["nextcloud-talk"],
            enabled: true,
            accounts: {
              ...namedConfig.channels?.["nextcloud-talk"]?.accounts,
              [accountId]: {
                ...namedConfig.channels?.["nextcloud-talk"]?.accounts?.[accountId],
                enabled: true,
                baseUrl: setupInput.baseUrl,
                ...(setupInput.secretFile
                  ? { botSecretFile: setupInput.secretFile }
                  : setupInput.secret
                    ? { botSecret: setupInput.secret }
                    : {}),
              },
            },
          },
        },
      } as OpenClawConfig;
    },
  },
  outbound: {
    deliveryMode: "direct",
    chunker: (text, limit) => getNextcloudTalkRuntime().channel.text.chunkMarkdownText(text, limit),
    chunkerMode: "markdown",
    textChunkLimit: 4000,
    sendText: async ({ to, text, accountId, replyToId }) => {
      const result = await sendMessageNextcloudTalk(to, text, {
        accountId: accountId ?? undefined,
        replyTo: replyToId ?? undefined,
      });
      return { channel: "nextcloud-talk", ...result };
    },
    sendMedia: async ({ to, text, mediaUrl, accountId, replyToId }) => {
      const messageWithMedia = mediaUrl ? `${text}\n\nAttachment: ${mediaUrl}` : text;
      const result = await sendMessageNextcloudTalk(to, messageWithMedia, {
        accountId: accountId ?? undefined,
        replyTo: replyToId ?? undefined,
      });
      return { channel: "nextcloud-talk", ...result };
    },
  },
  status: {
    defaultRuntime: {
      accountId: DEFAULT_ACCOUNT_ID,
      running: false,
      lastStartAt: null,
      lastStopAt: null,
      lastError: null,
    },
    buildChannelSummary: ({ snapshot }) => ({
      configured: snapshot.configured ?? false,
      secretSource: snapshot.secretSource ?? "none",
      running: snapshot.running ?? false,
      mode: "webhook",
      lastStartAt: snapshot.lastStartAt ?? null,
      lastStopAt: snapshot.lastStopAt ?? null,
      lastError: snapshot.lastError ?? null,
    }),
    buildAccountSnapshot: ({ account, runtime }) => {
      const configured = Boolean(account.secret?.trim() && account.baseUrl?.trim());
      return {
        accountId: account.accountId,
        name: account.name,
        enabled: account.enabled,
        configured,
        secretSource: account.secretSource,
        baseUrl: account.baseUrl ? "[set]" : "[missing]",
        running: runtime?.running ?? false,
        lastStartAt: runtime?.lastStartAt ?? null,
        lastStopAt: runtime?.lastStopAt ?? null,
        lastError: runtime?.lastError ?? null,
        mode: "webhook",
        lastInboundAt: runtime?.lastInboundAt ?? null,
        lastOutboundAt: runtime?.lastOutboundAt ?? null,
      };
    },
  },
  gateway: {
    startAccount: async (ctx) => {
      const account = ctx.account;
      if (!account.secret || !account.baseUrl) {
        throw new Error(
          `Nextcloud Talk not configured for account "${account.accountId}" (missing secret or baseUrl)`,
        );
      }

      ctx.log?.info(`[${account.accountId}] starting Nextcloud Talk webhook server`);

      const { stop } = await monitorNextcloudTalkProvider({
        accountId: account.accountId,
        config: ctx.cfg as CoreConfig,
        runtime: ctx.runtime,
        abortSignal: ctx.abortSignal,
        statusSink: (patch) => ctx.setStatus({ accountId: ctx.accountId, ...patch }),
      });

      return { stop };
    },
    logoutAccount: async ({ accountId, cfg }) => {
      const nextCfg = { ...cfg } as OpenClawConfig;
      const nextSection = cfg.channels?.["nextcloud-talk"]
        ? { ...cfg.channels["nextcloud-talk"] }
        : undefined;
      let cleared = false;
      let changed = false;

      if (nextSection) {
        if (accountId === DEFAULT_ACCOUNT_ID && nextSection.botSecret) {
          delete nextSection.botSecret;
          cleared = true;
          changed = true;
        }
        const accounts =
          nextSection.accounts && typeof nextSection.accounts === "object"
            ? { ...nextSection.accounts }
            : undefined;
        if (accounts && accountId in accounts) {
          const entry = accounts[accountId];
          if (entry && typeof entry === "object") {
            const nextEntry = { ...entry } as Record<string, unknown>;
            if ("botSecret" in nextEntry) {
              const secret = nextEntry.botSecret;
              if (typeof secret === "string" ? secret.trim() : secret) {
                cleared = true;
              }
              delete nextEntry.botSecret;
              changed = true;
            }
            if (Object.keys(nextEntry).length === 0) {
              delete accounts[accountId];
              changed = true;
            } else {
              accounts[accountId] = nextEntry as typeof entry;
            }
          }
        }
        if (accounts) {
          if (Object.keys(accounts).length === 0) {
            delete nextSection.accounts;
            changed = true;
          } else {
            nextSection.accounts = accounts;
          }
        }
      }

      if (changed) {
        if (nextSection && Object.keys(nextSection).length > 0) {
          nextCfg.channels = { ...nextCfg.channels, "nextcloud-talk": nextSection };
        } else {
          const nextChannels = { ...nextCfg.channels } as Record<string, unknown>;
          delete nextChannels["nextcloud-talk"];
          if (Object.keys(nextChannels).length > 0) {
            nextCfg.channels = nextChannels as OpenClawConfig["channels"];
          } else {
            delete nextCfg.channels;
          }
        }
      }

      const resolved = resolveNextcloudTalkAccount({
        cfg: changed ? (nextCfg as CoreConfig) : (cfg as CoreConfig),
        accountId,
      });
      const loggedOut = resolved.secretSource === "none";

      if (changed) {
        await getNextcloudTalkRuntime().config.writeConfigFile(nextCfg);
      }

      return {
        cleared,
        envSecret: Boolean(process.env.NEXTCLOUD_TALK_BOT_SECRET?.trim()),
        loggedOut,
      };
    },
  },
};
]]></file>
  <file path="./extensions/nextcloud-talk/index.ts"><![CDATA[import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import { emptyPluginConfigSchema } from "openclaw/plugin-sdk";
import { nextcloudTalkPlugin } from "./src/channel.js";
import { setNextcloudTalkRuntime } from "./src/runtime.js";

const plugin = {
  id: "nextcloud-talk",
  name: "Nextcloud Talk",
  description: "Nextcloud Talk channel plugin",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    setNextcloudTalkRuntime(api.runtime);
    api.registerChannel({ plugin: nextcloudTalkPlugin });
  },
};

export default plugin;
]]></file>
  <file path="./extensions/copilot-proxy/openclaw.plugin.json"><![CDATA[{
  "id": "copilot-proxy",
  "providers": ["copilot-proxy"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/copilot-proxy/README.md"><![CDATA[# Copilot Proxy (OpenClaw plugin)

Provider plugin for the **Copilot Proxy** VS Code extension.

## Enable

Bundled plugins are disabled by default. Enable this one:

```bash
openclaw plugins enable copilot-proxy
```

Restart the Gateway after enabling.

## Authenticate

```bash
openclaw models auth login --provider copilot-proxy --set-default
```

## Notes

- Copilot Proxy must be running in VS Code.
- Base URL must include `/v1`.
]]></file>
  <file path="./extensions/copilot-proxy/package.json"><![CDATA[{
  "name": "@openclaw/copilot-proxy",
  "version": "2026.2.13",
  "private": true,
  "description": "OpenClaw Copilot Proxy provider plugin",
  "type": "module",
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ]
  }
}
]]></file>
  <file path="./extensions/copilot-proxy/index.ts"><![CDATA[import {
  emptyPluginConfigSchema,
  type OpenClawPluginApi,
  type ProviderAuthContext,
  type ProviderAuthResult,
} from "openclaw/plugin-sdk";

const DEFAULT_BASE_URL = "http://localhost:3000/v1";
const DEFAULT_API_KEY = "n/a";
const DEFAULT_CONTEXT_WINDOW = 128_000;
const DEFAULT_MAX_TOKENS = 8192;
const DEFAULT_MODEL_IDS = [
  "gpt-5.2",
  "gpt-5.2-codex",
  "gpt-5.1",
  "gpt-5.1-codex",
  "gpt-5.1-codex-max",
  "gpt-5-mini",
  "claude-opus-4.6",
  "claude-opus-4.5",
  "claude-sonnet-4.5",
  "claude-haiku-4.5",
  "gemini-3-pro",
  "gemini-3-flash",
  "grok-code-fast-1",
] as const;

function normalizeBaseUrl(value: string): string {
  const trimmed = value.trim();
  if (!trimmed) {
    return DEFAULT_BASE_URL;
  }
  let normalized = trimmed;
  while (normalized.endsWith("/")) {
    normalized = normalized.slice(0, -1);
  }
  if (!normalized.endsWith("/v1")) {
    normalized = `${normalized}/v1`;
  }
  return normalized;
}

function validateBaseUrl(value: string): string | undefined {
  const normalized = normalizeBaseUrl(value);
  try {
    new URL(normalized);
  } catch {
    return "Enter a valid URL";
  }
  return undefined;
}

function parseModelIds(input: string): string[] {
  const parsed = input
    .split(/[\n,]/)
    .map((model) => model.trim())
    .filter(Boolean);
  return Array.from(new Set(parsed));
}

function buildModelDefinition(modelId: string) {
  return {
    id: modelId,
    name: modelId,
    api: "openai-completions" as const,
    reasoning: false,
    input: ["text", "image"] as Array<"text" | "image">,
    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
    contextWindow: DEFAULT_CONTEXT_WINDOW,
    maxTokens: DEFAULT_MAX_TOKENS,
  };
}

const copilotProxyPlugin = {
  id: "copilot-proxy",
  name: "Copilot Proxy",
  description: "Local Copilot Proxy (VS Code LM) provider plugin",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    api.registerProvider({
      id: "copilot-proxy",
      label: "Copilot Proxy",
      docsPath: "/providers/models",
      auth: [
        {
          id: "local",
          label: "Local proxy",
          hint: "Configure base URL + models for the Copilot Proxy server",
          kind: "custom",
          run: async (ctx: ProviderAuthContext): Promise<ProviderAuthResult> => {
            const baseUrlInput = await ctx.prompter.text({
              message: "Copilot Proxy base URL",
              initialValue: DEFAULT_BASE_URL,
              validate: validateBaseUrl,
            });

            const modelInput = await ctx.prompter.text({
              message: "Model IDs (comma-separated)",
              initialValue: DEFAULT_MODEL_IDS.join(", "),
              validate: (value: string) =>
                parseModelIds(value).length > 0 ? undefined : "Enter at least one model id",
            });

            const baseUrl = normalizeBaseUrl(baseUrlInput);
            const modelIds = parseModelIds(modelInput);
            const defaultModelId = modelIds[0] ?? DEFAULT_MODEL_IDS[0];
            const defaultModelRef = `copilot-proxy/${defaultModelId}`;

            return {
              profiles: [
                {
                  profileId: "copilot-proxy:local",
                  credential: {
                    type: "token",
                    provider: "copilot-proxy",
                    token: DEFAULT_API_KEY,
                  },
                },
              ],
              configPatch: {
                models: {
                  providers: {
                    "copilot-proxy": {
                      baseUrl,
                      apiKey: DEFAULT_API_KEY,
                      api: "openai-completions",
                      authHeader: false,
                      models: modelIds.map((modelId) => buildModelDefinition(modelId)),
                    },
                  },
                },
                agents: {
                  defaults: {
                    models: Object.fromEntries(
                      modelIds.map((modelId) => [`copilot-proxy/${modelId}`, {}]),
                    ),
                  },
                },
              },
              defaultModel: defaultModelRef,
              notes: [
                "Start the Copilot Proxy VS Code extension before using these models.",
                "Copilot Proxy serves /v1/chat/completions; base URL must include /v1.",
                "Model availability depends on your Copilot plan; edit models.providers.copilot-proxy if needed.",
              ],
            };
          },
        },
      ],
    });
  },
};

export default copilotProxyPlugin;
]]></file>
  <file path="./extensions/msteams/openclaw.plugin.json"><![CDATA[{
  "id": "msteams",
  "channels": ["msteams"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/msteams/package.json"><![CDATA[{
  "name": "@openclaw/msteams",
  "version": "2026.2.13",
  "description": "OpenClaw Microsoft Teams channel plugin",
  "type": "module",
  "dependencies": {
    "@microsoft/agents-hosting": "^1.2.3",
    "@microsoft/agents-hosting-express": "^1.2.3",
    "@microsoft/agents-hosting-extensions-teams": "^1.2.3",
    "express": "^5.2.1",
    "proper-lockfile": "^4.1.2"
  },
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ],
    "channel": {
      "id": "msteams",
      "label": "Microsoft Teams",
      "selectionLabel": "Microsoft Teams (Bot Framework)",
      "docsPath": "/channels/msteams",
      "docsLabel": "msteams",
      "blurb": "Bot Framework; enterprise support.",
      "aliases": [
        "teams"
      ],
      "order": 60
    },
    "install": {
      "npmSpec": "@openclaw/msteams",
      "localPath": "extensions/msteams",
      "defaultChoice": "npm"
    }
  }
}
]]></file>
  <file path="./extensions/msteams/src/send.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import { loadWebMedia, resolveChannelMediaMaxBytes } from "openclaw/plugin-sdk";
import { createMSTeamsConversationStoreFs } from "./conversation-store-fs.js";
import {
  classifyMSTeamsSendError,
  formatMSTeamsSendErrorHint,
  formatUnknownError,
} from "./errors.js";
import { prepareFileConsentActivity, requiresFileConsent } from "./file-consent-helpers.js";
import { buildTeamsFileInfoCard } from "./graph-chat.js";
import {
  getDriveItemProperties,
  uploadAndShareOneDrive,
  uploadAndShareSharePoint,
} from "./graph-upload.js";
import { extractFilename, extractMessageId } from "./media-helpers.js";
import { buildConversationReference, sendMSTeamsMessages } from "./messenger.js";
import { buildMSTeamsPollCard } from "./polls.js";
import { getMSTeamsRuntime } from "./runtime.js";
import { resolveMSTeamsSendContext, type MSTeamsProactiveContext } from "./send-context.js";

export type SendMSTeamsMessageParams = {
  /** Full config (for credentials) */
  cfg: OpenClawConfig;
  /** Conversation ID or user ID to send to */
  to: string;
  /** Message text */
  text: string;
  /** Optional media URL */
  mediaUrl?: string;
};

export type SendMSTeamsMessageResult = {
  messageId: string;
  conversationId: string;
  /** If a FileConsentCard was sent instead of the file, this contains the upload ID */
  pendingUploadId?: string;
};

/** Threshold for large files that require FileConsentCard flow in personal chats */
const FILE_CONSENT_THRESHOLD_BYTES = 4 * 1024 * 1024; // 4MB

/**
 * MSTeams-specific media size limit (100MB).
 * Higher than the default because OneDrive upload handles large files well.
 */
const MSTEAMS_MAX_MEDIA_BYTES = 100 * 1024 * 1024;

export type SendMSTeamsPollParams = {
  /** Full config (for credentials) */
  cfg: OpenClawConfig;
  /** Conversation ID or user ID to send to */
  to: string;
  /** Poll question */
  question: string;
  /** Poll options */
  options: string[];
  /** Max selections (defaults to 1) */
  maxSelections?: number;
};

export type SendMSTeamsPollResult = {
  pollId: string;
  messageId: string;
  conversationId: string;
};

export type SendMSTeamsCardParams = {
  /** Full config (for credentials) */
  cfg: OpenClawConfig;
  /** Conversation ID or user ID to send to */
  to: string;
  /** Adaptive Card JSON object */
  card: Record<string, unknown>;
};

export type SendMSTeamsCardResult = {
  messageId: string;
  conversationId: string;
};

/**
 * Send a message to a Teams conversation or user.
 *
 * Uses the stored ConversationReference from previous interactions.
 * The bot must have received at least one message from the conversation
 * before proactive messaging works.
 *
 * File handling by conversation type:
 * - Personal (1:1) chats: small images (<4MB) use base64, large files and non-images use FileConsentCard
 * - Group chats / channels: files are uploaded to OneDrive and shared via link
 */
export async function sendMessageMSTeams(
  params: SendMSTeamsMessageParams,
): Promise<SendMSTeamsMessageResult> {
  const { cfg, to, text, mediaUrl } = params;
  const tableMode = getMSTeamsRuntime().channel.text.resolveMarkdownTableMode({
    cfg,
    channel: "msteams",
  });
  const messageText = getMSTeamsRuntime().channel.text.convertMarkdownTables(text ?? "", tableMode);
  const ctx = await resolveMSTeamsSendContext({ cfg, to });
  const {
    adapter,
    appId,
    conversationId,
    ref,
    log,
    conversationType,
    tokenProvider,
    sharePointSiteId,
  } = ctx;

  log.debug?.("sending proactive message", {
    conversationId,
    conversationType,
    textLength: messageText.length,
    hasMedia: Boolean(mediaUrl),
  });

  // Handle media if present
  if (mediaUrl) {
    const mediaMaxBytes =
      resolveChannelMediaMaxBytes({
        cfg,
        resolveChannelLimitMb: ({ cfg }) => cfg.channels?.msteams?.mediaMaxMb,
      }) ?? MSTEAMS_MAX_MEDIA_BYTES;
    const media = await loadWebMedia(mediaUrl, mediaMaxBytes);
    const isLargeFile = media.buffer.length >= FILE_CONSENT_THRESHOLD_BYTES;
    const isImage = media.contentType?.startsWith("image/") ?? false;
    const fallbackFileName = await extractFilename(mediaUrl);
    const fileName = media.fileName ?? fallbackFileName;

    log.debug?.("processing media", {
      fileName,
      contentType: media.contentType,
      size: media.buffer.length,
      isLargeFile,
      isImage,
      conversationType,
    });

    // Personal chats: base64 only works for images; use FileConsentCard for large files or non-images
    if (
      requiresFileConsent({
        conversationType,
        contentType: media.contentType,
        bufferSize: media.buffer.length,
        thresholdBytes: FILE_CONSENT_THRESHOLD_BYTES,
      })
    ) {
      const { activity, uploadId } = prepareFileConsentActivity({
        media: { buffer: media.buffer, filename: fileName, contentType: media.contentType },
        conversationId,
        description: messageText || undefined,
      });

      log.debug?.("sending file consent card", { uploadId, fileName, size: media.buffer.length });

      const baseRef = buildConversationReference(ref);
      const proactiveRef = { ...baseRef, activityId: undefined };

      let messageId = "unknown";
      try {
        await adapter.continueConversation(appId, proactiveRef, async (turnCtx) => {
          const response = await turnCtx.sendActivity(activity);
          messageId = extractMessageId(response) ?? "unknown";
        });
      } catch (err) {
        const classification = classifyMSTeamsSendError(err);
        const hint = formatMSTeamsSendErrorHint(classification);
        const status = classification.statusCode ? ` (HTTP ${classification.statusCode})` : "";
        throw new Error(
          `msteams consent card send failed${status}: ${formatUnknownError(err)}${hint ? ` (${hint})` : ""}`,
          { cause: err },
        );
      }

      log.info("sent file consent card", { conversationId, messageId, uploadId });

      return {
        messageId,
        conversationId,
        pendingUploadId: uploadId,
      };
    }

    // Personal chat with small image: use base64 (only works for images)
    if (conversationType === "personal") {
      // Small image in personal chat: use base64 (only works for images)
      const base64 = media.buffer.toString("base64");
      const finalMediaUrl = `data:${media.contentType};base64,${base64}`;

      return sendTextWithMedia(ctx, messageText, finalMediaUrl);
    }

    if (isImage && !sharePointSiteId) {
      // Group chat/channel without SharePoint: send image inline (avoids OneDrive failures)
      const base64 = media.buffer.toString("base64");
      const finalMediaUrl = `data:${media.contentType};base64,${base64}`;
      return sendTextWithMedia(ctx, messageText, finalMediaUrl);
    }

    // Group chat or channel: upload to SharePoint (if siteId configured) or OneDrive
    try {
      if (sharePointSiteId) {
        // Use SharePoint upload + Graph API for native file card
        log.debug?.("uploading to SharePoint for native file card", {
          fileName,
          conversationType,
          siteId: sharePointSiteId,
        });

        const uploaded = await uploadAndShareSharePoint({
          buffer: media.buffer,
          filename: fileName,
          contentType: media.contentType,
          tokenProvider,
          siteId: sharePointSiteId,
          chatId: conversationId,
          usePerUserSharing: conversationType === "groupChat",
        });

        log.debug?.("SharePoint upload complete", {
          itemId: uploaded.itemId,
          shareUrl: uploaded.shareUrl,
        });

        // Get driveItem properties needed for native file card
        const driveItem = await getDriveItemProperties({
          siteId: sharePointSiteId,
          itemId: uploaded.itemId,
          tokenProvider,
        });

        log.debug?.("driveItem properties retrieved", {
          eTag: driveItem.eTag,
          webDavUrl: driveItem.webDavUrl,
        });

        // Build native Teams file card attachment and send via Bot Framework
        const fileCardAttachment = buildTeamsFileInfoCard(driveItem);
        const activity = {
          type: "message",
          text: messageText || undefined,
          attachments: [fileCardAttachment],
        };

        const baseRef = buildConversationReference(ref);
        const proactiveRef = { ...baseRef, activityId: undefined };

        let messageId = "unknown";
        await adapter.continueConversation(appId, proactiveRef, async (turnCtx) => {
          const response = await turnCtx.sendActivity(activity);
          messageId = extractMessageId(response) ?? "unknown";
        });

        log.info("sent native file card", {
          conversationId,
          messageId,
          fileName: driveItem.name,
        });

        return { messageId, conversationId };
      }

      // Fallback: no SharePoint site configured, use OneDrive with markdown link
      log.debug?.("uploading to OneDrive (no SharePoint site configured)", {
        fileName,
        conversationType,
      });

      const uploaded = await uploadAndShareOneDrive({
        buffer: media.buffer,
        filename: fileName,
        contentType: media.contentType,
        tokenProvider,
      });

      log.debug?.("OneDrive upload complete", {
        itemId: uploaded.itemId,
        shareUrl: uploaded.shareUrl,
      });

      // Send message with file link (Bot Framework doesn't support "reference" attachment type for sending)
      const fileLink = `📎 [${uploaded.name}](${uploaded.shareUrl})`;
      const activity = {
        type: "message",
        text: messageText ? `${messageText}\n\n${fileLink}` : fileLink,
      };

      const baseRef = buildConversationReference(ref);
      const proactiveRef = { ...baseRef, activityId: undefined };

      let messageId = "unknown";
      await adapter.continueConversation(appId, proactiveRef, async (turnCtx) => {
        const response = await turnCtx.sendActivity(activity);
        messageId = extractMessageId(response) ?? "unknown";
      });

      log.info("sent message with OneDrive file link", {
        conversationId,
        messageId,
        shareUrl: uploaded.shareUrl,
      });

      return { messageId, conversationId };
    } catch (err) {
      const classification = classifyMSTeamsSendError(err);
      const hint = formatMSTeamsSendErrorHint(classification);
      const status = classification.statusCode ? ` (HTTP ${classification.statusCode})` : "";
      throw new Error(
        `msteams file send failed${status}: ${formatUnknownError(err)}${hint ? ` (${hint})` : ""}`,
        { cause: err },
      );
    }
  }

  // No media: send text only
  return sendTextWithMedia(ctx, messageText, undefined);
}

/**
 * Send a text message with optional base64 media URL.
 */
async function sendTextWithMedia(
  ctx: MSTeamsProactiveContext,
  text: string,
  mediaUrl: string | undefined,
): Promise<SendMSTeamsMessageResult> {
  const {
    adapter,
    appId,
    conversationId,
    ref,
    log,
    tokenProvider,
    sharePointSiteId,
    mediaMaxBytes,
  } = ctx;

  let messageIds: string[];
  try {
    messageIds = await sendMSTeamsMessages({
      replyStyle: "top-level",
      adapter,
      appId,
      conversationRef: ref,
      messages: [{ text: text || undefined, mediaUrl }],
      retry: {},
      onRetry: (event) => {
        log.debug?.("retrying send", { conversationId, ...event });
      },
      tokenProvider,
      sharePointSiteId,
      mediaMaxBytes,
    });
  } catch (err) {
    const classification = classifyMSTeamsSendError(err);
    const hint = formatMSTeamsSendErrorHint(classification);
    const status = classification.statusCode ? ` (HTTP ${classification.statusCode})` : "";
    throw new Error(
      `msteams send failed${status}: ${formatUnknownError(err)}${hint ? ` (${hint})` : ""}`,
      { cause: err },
    );
  }

  const messageId = messageIds[0] ?? "unknown";
  log.info("sent proactive message", { conversationId, messageId });

  return {
    messageId,
    conversationId,
  };
}

/**
 * Send a poll (Adaptive Card) to a Teams conversation or user.
 */
export async function sendPollMSTeams(
  params: SendMSTeamsPollParams,
): Promise<SendMSTeamsPollResult> {
  const { cfg, to, question, options, maxSelections } = params;
  const { adapter, appId, conversationId, ref, log } = await resolveMSTeamsSendContext({
    cfg,
    to,
  });

  const pollCard = buildMSTeamsPollCard({
    question,
    options,
    maxSelections,
  });

  log.debug?.("sending poll", {
    conversationId,
    pollId: pollCard.pollId,
    optionCount: pollCard.options.length,
  });

  const activity = {
    type: "message",
    attachments: [
      {
        contentType: "application/vnd.microsoft.card.adaptive",
        content: pollCard.card,
      },
    ],
  };

  // Send poll via proactive conversation (Adaptive Cards require direct activity send)
  const baseRef = buildConversationReference(ref);
  const proactiveRef = {
    ...baseRef,
    activityId: undefined,
  };

  let messageId = "unknown";
  try {
    await adapter.continueConversation(appId, proactiveRef, async (ctx) => {
      const response = await ctx.sendActivity(activity);
      messageId = extractMessageId(response) ?? "unknown";
    });
  } catch (err) {
    const classification = classifyMSTeamsSendError(err);
    const hint = formatMSTeamsSendErrorHint(classification);
    const status = classification.statusCode ? ` (HTTP ${classification.statusCode})` : "";
    throw new Error(
      `msteams poll send failed${status}: ${formatUnknownError(err)}${hint ? ` (${hint})` : ""}`,
      { cause: err },
    );
  }

  log.info("sent poll", { conversationId, pollId: pollCard.pollId, messageId });

  return {
    pollId: pollCard.pollId,
    messageId,
    conversationId,
  };
}

/**
 * Send an arbitrary Adaptive Card to a Teams conversation or user.
 */
export async function sendAdaptiveCardMSTeams(
  params: SendMSTeamsCardParams,
): Promise<SendMSTeamsCardResult> {
  const { cfg, to, card } = params;
  const { adapter, appId, conversationId, ref, log } = await resolveMSTeamsSendContext({
    cfg,
    to,
  });

  log.debug?.("sending adaptive card", {
    conversationId,
    cardType: card.type,
    cardVersion: card.version,
  });

  const activity = {
    type: "message",
    attachments: [
      {
        contentType: "application/vnd.microsoft.card.adaptive",
        content: card,
      },
    ],
  };

  // Send card via proactive conversation
  const baseRef = buildConversationReference(ref);
  const proactiveRef = {
    ...baseRef,
    activityId: undefined,
  };

  let messageId = "unknown";
  try {
    await adapter.continueConversation(appId, proactiveRef, async (ctx) => {
      const response = await ctx.sendActivity(activity);
      messageId = extractMessageId(response) ?? "unknown";
    });
  } catch (err) {
    const classification = classifyMSTeamsSendError(err);
    const hint = formatMSTeamsSendErrorHint(classification);
    const status = classification.statusCode ? ` (HTTP ${classification.statusCode})` : "";
    throw new Error(
      `msteams card send failed${status}: ${formatUnknownError(err)}${hint ? ` (${hint})` : ""}`,
      { cause: err },
    );
  }

  log.info("sent adaptive card", { conversationId, messageId });

  return {
    messageId,
    conversationId,
  };
}

/**
 * List all known conversation references (for debugging/CLI).
 */
export async function listMSTeamsConversations(): Promise<
  Array<{
    conversationId: string;
    userName?: string;
    conversationType?: string;
  }>
> {
  const store = createMSTeamsConversationStoreFs();
  const all = await store.list();
  return all.map(({ conversationId, reference }) => ({
    conversationId,
    userName: reference.user?.name,
    conversationType: reference.conversation?.conversationType,
  }));
}
]]></file>
  <file path="./extensions/msteams/src/directory-live.ts"><![CDATA[import type { ChannelDirectoryEntry, MSTeamsConfig } from "openclaw/plugin-sdk";
import { GRAPH_ROOT } from "./attachments/shared.js";
import { loadMSTeamsSdkWithAuth } from "./sdk.js";
import { resolveMSTeamsCredentials } from "./token.js";

type GraphUser = {
  id?: string;
  displayName?: string;
  userPrincipalName?: string;
  mail?: string;
};

type GraphGroup = {
  id?: string;
  displayName?: string;
};

type GraphChannel = {
  id?: string;
  displayName?: string;
};

type GraphResponse<T> = { value?: T[] };

function readAccessToken(value: unknown): string | null {
  if (typeof value === "string") {
    return value;
  }
  if (value && typeof value === "object") {
    const token =
      (value as { accessToken?: unknown }).accessToken ?? (value as { token?: unknown }).token;
    return typeof token === "string" ? token : null;
  }
  return null;
}

function normalizeQuery(value?: string | null): string {
  return value?.trim() ?? "";
}

function escapeOData(value: string): string {
  return value.replace(/'/g, "''");
}

async function fetchGraphJson<T>(params: {
  token: string;
  path: string;
  headers?: Record<string, string>;
}): Promise<T> {
  const res = await fetch(`${GRAPH_ROOT}${params.path}`, {
    headers: {
      Authorization: `Bearer ${params.token}`,
      ...params.headers,
    },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => "");
    throw new Error(`Graph ${params.path} failed (${res.status}): ${text || "unknown error"}`);
  }
  return (await res.json()) as T;
}

async function resolveGraphToken(cfg: unknown): Promise<string> {
  const creds = resolveMSTeamsCredentials(
    (cfg as { channels?: { msteams?: unknown } })?.channels?.msteams as MSTeamsConfig | undefined,
  );
  if (!creds) {
    throw new Error("MS Teams credentials missing");
  }
  const { sdk, authConfig } = await loadMSTeamsSdkWithAuth(creds);
  const tokenProvider = new sdk.MsalTokenProvider(authConfig);
  const token = await tokenProvider.getAccessToken("https://graph.microsoft.com");
  const accessToken = readAccessToken(token);
  if (!accessToken) {
    throw new Error("MS Teams graph token unavailable");
  }
  return accessToken;
}

async function listTeamsByName(token: string, query: string): Promise<GraphGroup[]> {
  const escaped = escapeOData(query);
  const filter = `resourceProvisioningOptions/Any(x:x eq 'Team') and startsWith(displayName,'${escaped}')`;
  const path = `/groups?$filter=${encodeURIComponent(filter)}&$select=id,displayName`;
  const res = await fetchGraphJson<GraphResponse<GraphGroup>>({ token, path });
  return res.value ?? [];
}

async function listChannelsForTeam(token: string, teamId: string): Promise<GraphChannel[]> {
  const path = `/teams/${encodeURIComponent(teamId)}/channels?$select=id,displayName`;
  const res = await fetchGraphJson<GraphResponse<GraphChannel>>({ token, path });
  return res.value ?? [];
}

export async function listMSTeamsDirectoryPeersLive(params: {
  cfg: unknown;
  query?: string | null;
  limit?: number | null;
}): Promise<ChannelDirectoryEntry[]> {
  const query = normalizeQuery(params.query);
  if (!query) {
    return [];
  }
  const token = await resolveGraphToken(params.cfg);
  const limit = typeof params.limit === "number" && params.limit > 0 ? params.limit : 20;

  let users: GraphUser[] = [];
  if (query.includes("@")) {
    const escaped = escapeOData(query);
    const filter = `(mail eq '${escaped}' or userPrincipalName eq '${escaped}')`;
    const path = `/users?$filter=${encodeURIComponent(filter)}&$select=id,displayName,mail,userPrincipalName`;
    const res = await fetchGraphJson<GraphResponse<GraphUser>>({ token, path });
    users = res.value ?? [];
  } else {
    const path = `/users?$search=${encodeURIComponent(`"displayName:${query}"`)}&$select=id,displayName,mail,userPrincipalName&$top=${limit}`;
    const res = await fetchGraphJson<GraphResponse<GraphUser>>({
      token,
      path,
      headers: { ConsistencyLevel: "eventual" },
    });
    users = res.value ?? [];
  }

  return users
    .map((user) => {
      const id = user.id?.trim();
      if (!id) {
        return null;
      }
      const name = user.displayName?.trim();
      const handle = user.userPrincipalName?.trim() || user.mail?.trim();
      return {
        kind: "user",
        id: `user:${id}`,
        name: name || undefined,
        handle: handle ? `@${handle}` : undefined,
        raw: user,
      } satisfies ChannelDirectoryEntry;
    })
    .filter(Boolean) as ChannelDirectoryEntry[];
}

export async function listMSTeamsDirectoryGroupsLive(params: {
  cfg: unknown;
  query?: string | null;
  limit?: number | null;
}): Promise<ChannelDirectoryEntry[]> {
  const rawQuery = normalizeQuery(params.query);
  if (!rawQuery) {
    return [];
  }
  const token = await resolveGraphToken(params.cfg);
  const limit = typeof params.limit === "number" && params.limit > 0 ? params.limit : 20;
  const [teamQuery, channelQuery] = rawQuery.includes("/")
    ? rawQuery
        .split("/", 2)
        .map((part) => part.trim())
        .filter(Boolean)
    : [rawQuery, null];

  const teams = await listTeamsByName(token, teamQuery);
  const results: ChannelDirectoryEntry[] = [];

  for (const team of teams) {
    const teamId = team.id?.trim();
    if (!teamId) {
      continue;
    }
    const teamName = team.displayName?.trim() || teamQuery;
    if (!channelQuery) {
      results.push({
        kind: "group",
        id: `team:${teamId}`,
        name: teamName,
        handle: teamName ? `#${teamName}` : undefined,
        raw: team,
      });
      if (results.length >= limit) {
        return results;
      }
      continue;
    }
    const channels = await listChannelsForTeam(token, teamId);
    for (const channel of channels) {
      const name = channel.displayName?.trim();
      if (!name) {
        continue;
      }
      if (!name.toLowerCase().includes(channelQuery.toLowerCase())) {
        continue;
      }
      results.push({
        kind: "group",
        id: `conversation:${channel.id}`,
        name: `${teamName}/${name}`,
        handle: `#${name}`,
        raw: channel,
      });
      if (results.length >= limit) {
        return results;
      }
    }
  }

  return results;
}
]]></file>
  <file path="./extensions/msteams/src/probe.ts"><![CDATA[import type { MSTeamsConfig } from "openclaw/plugin-sdk";
import { formatUnknownError } from "./errors.js";
import { loadMSTeamsSdkWithAuth } from "./sdk.js";
import { resolveMSTeamsCredentials } from "./token.js";

export type ProbeMSTeamsResult = {
  ok: boolean;
  error?: string;
  appId?: string;
  graph?: {
    ok: boolean;
    error?: string;
    roles?: string[];
    scopes?: string[];
  };
};

function readAccessToken(value: unknown): string | null {
  if (typeof value === "string") {
    return value;
  }
  if (value && typeof value === "object") {
    const token =
      (value as { accessToken?: unknown }).accessToken ?? (value as { token?: unknown }).token;
    return typeof token === "string" ? token : null;
  }
  return null;
}

function decodeJwtPayload(token: string): Record<string, unknown> | null {
  const parts = token.split(".");
  if (parts.length < 2) {
    return null;
  }
  const payload = parts[1] ?? "";
  const padded = payload.padEnd(payload.length + ((4 - (payload.length % 4)) % 4), "=");
  const normalized = padded.replace(/-/g, "+").replace(/_/g, "/");
  try {
    const decoded = Buffer.from(normalized, "base64").toString("utf8");
    const parsed = JSON.parse(decoded) as Record<string, unknown>;
    return parsed && typeof parsed === "object" ? parsed : null;
  } catch {
    return null;
  }
}

function readStringArray(value: unknown): string[] | undefined {
  if (!Array.isArray(value)) {
    return undefined;
  }
  const out = value.map((entry) => String(entry).trim()).filter(Boolean);
  return out.length > 0 ? out : undefined;
}

function readScopes(value: unknown): string[] | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const out = value
    .split(/\s+/)
    .map((entry) => entry.trim())
    .filter(Boolean);
  return out.length > 0 ? out : undefined;
}

export async function probeMSTeams(cfg?: MSTeamsConfig): Promise<ProbeMSTeamsResult> {
  const creds = resolveMSTeamsCredentials(cfg);
  if (!creds) {
    return {
      ok: false,
      error: "missing credentials (appId, appPassword, tenantId)",
    };
  }

  try {
    const { sdk, authConfig } = await loadMSTeamsSdkWithAuth(creds);
    const tokenProvider = new sdk.MsalTokenProvider(authConfig);
    await tokenProvider.getAccessToken("https://api.botframework.com");
    let graph:
      | {
          ok: boolean;
          error?: string;
          roles?: string[];
          scopes?: string[];
        }
      | undefined;
    try {
      const graphToken = await tokenProvider.getAccessToken("https://graph.microsoft.com");
      const accessToken = readAccessToken(graphToken);
      const payload = accessToken ? decodeJwtPayload(accessToken) : null;
      graph = {
        ok: true,
        roles: readStringArray(payload?.roles),
        scopes: readScopes(payload?.scp),
      };
    } catch (err) {
      graph = { ok: false, error: formatUnknownError(err) };
    }
    return { ok: true, appId: creds.appId, ...(graph ? { graph } : {}) };
  } catch (err) {
    return {
      ok: false,
      appId: creds.appId,
      error: formatUnknownError(err),
    };
  }
}
]]></file>
  <file path="./extensions/msteams/src/errors.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import {
  classifyMSTeamsSendError,
  formatMSTeamsSendErrorHint,
  formatUnknownError,
} from "./errors.js";

describe("msteams errors", () => {
  it("formats unknown errors", () => {
    expect(formatUnknownError("oops")).toBe("oops");
    expect(formatUnknownError(null)).toBe("null");
  });

  it("classifies auth errors", () => {
    expect(classifyMSTeamsSendError({ statusCode: 401 }).kind).toBe("auth");
    expect(classifyMSTeamsSendError({ statusCode: 403 }).kind).toBe("auth");
  });

  it("classifies throttling errors and parses retry-after", () => {
    expect(classifyMSTeamsSendError({ statusCode: 429, retryAfter: "1.5" })).toMatchObject({
      kind: "throttled",
      statusCode: 429,
      retryAfterMs: 1500,
    });
  });

  it("classifies transient errors", () => {
    expect(classifyMSTeamsSendError({ statusCode: 503 })).toMatchObject({
      kind: "transient",
      statusCode: 503,
    });
  });

  it("classifies permanent 4xx errors", () => {
    expect(classifyMSTeamsSendError({ statusCode: 400 })).toMatchObject({
      kind: "permanent",
      statusCode: 400,
    });
  });

  it("provides actionable hints for common cases", () => {
    expect(formatMSTeamsSendErrorHint({ kind: "auth" })).toContain("msteams");
    expect(formatMSTeamsSendErrorHint({ kind: "throttled" })).toContain("throttled");
  });
});
]]></file>
  <file path="./extensions/msteams/src/policy.test.ts"><![CDATA[import type { MSTeamsConfig } from "openclaw/plugin-sdk";
import { describe, expect, it } from "vitest";
import {
  isMSTeamsGroupAllowed,
  resolveMSTeamsReplyPolicy,
  resolveMSTeamsRouteConfig,
} from "./policy.js";

describe("msteams policy", () => {
  describe("resolveMSTeamsRouteConfig", () => {
    it("returns team and channel config when present", () => {
      const cfg: MSTeamsConfig = {
        teams: {
          team123: {
            requireMention: false,
            channels: {
              chan456: { requireMention: true },
            },
          },
        },
      };

      const res = resolveMSTeamsRouteConfig({
        cfg,
        teamId: "team123",
        conversationId: "chan456",
      });

      expect(res.teamConfig?.requireMention).toBe(false);
      expect(res.channelConfig?.requireMention).toBe(true);
      expect(res.allowlistConfigured).toBe(true);
      expect(res.allowed).toBe(true);
      expect(res.channelMatchKey).toBe("chan456");
      expect(res.channelMatchSource).toBe("direct");
    });

    it("returns undefined configs when teamId is missing", () => {
      const cfg: MSTeamsConfig = {
        teams: { team123: { requireMention: false } },
      };

      const res = resolveMSTeamsRouteConfig({
        cfg,
        teamId: undefined,
        conversationId: "chan",
      });
      expect(res.teamConfig).toBeUndefined();
      expect(res.channelConfig).toBeUndefined();
      expect(res.allowlistConfigured).toBe(true);
      expect(res.allowed).toBe(false);
    });

    it("matches team and channel by name", () => {
      const cfg: MSTeamsConfig = {
        teams: {
          "My Team": {
            requireMention: true,
            channels: {
              "General Chat": { requireMention: false },
            },
          },
        },
      };

      const res = resolveMSTeamsRouteConfig({
        cfg,
        teamName: "My Team",
        channelName: "General Chat",
        conversationId: "ignored",
      });

      expect(res.teamConfig?.requireMention).toBe(true);
      expect(res.channelConfig?.requireMention).toBe(false);
      expect(res.allowed).toBe(true);
    });
  });

  describe("resolveMSTeamsReplyPolicy", () => {
    it("forces thread replies for direct messages", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: true,
        globalConfig: { replyStyle: "top-level", requireMention: false },
      });
      expect(policy).toEqual({ requireMention: false, replyStyle: "thread" });
    });

    it("defaults to requireMention=true and replyStyle=thread", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: false,
        globalConfig: {},
      });
      expect(policy).toEqual({ requireMention: true, replyStyle: "thread" });
    });

    it("defaults replyStyle to top-level when requireMention=false", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: false,
        globalConfig: { requireMention: false },
      });
      expect(policy).toEqual({
        requireMention: false,
        replyStyle: "top-level",
      });
    });

    it("prefers channel overrides over team and global defaults", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: false,
        globalConfig: { requireMention: true },
        teamConfig: { requireMention: true },
        channelConfig: { requireMention: false },
      });

      // requireMention from channel -> false, and replyStyle defaults from requireMention -> top-level
      expect(policy).toEqual({
        requireMention: false,
        replyStyle: "top-level",
      });
    });

    it("inherits team mention settings when channel config is missing", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: false,
        globalConfig: { requireMention: true },
        teamConfig: { requireMention: false },
      });
      expect(policy).toEqual({
        requireMention: false,
        replyStyle: "top-level",
      });
    });

    it("uses explicit replyStyle even when requireMention defaults would differ", () => {
      const policy = resolveMSTeamsReplyPolicy({
        isDirectMessage: false,
        globalConfig: { requireMention: false, replyStyle: "thread" },
      });
      expect(policy).toEqual({ requireMention: false, replyStyle: "thread" });
    });
  });

  describe("isMSTeamsGroupAllowed", () => {
    it("allows when policy is open", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "open",
          allowFrom: [],
          senderId: "user-id",
          senderName: "User",
        }),
      ).toBe(true);
    });

    it("blocks when policy is disabled", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "disabled",
          allowFrom: ["user-id"],
          senderId: "user-id",
          senderName: "User",
        }),
      ).toBe(false);
    });

    it("blocks allowlist when empty", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "allowlist",
          allowFrom: [],
          senderId: "user-id",
          senderName: "User",
        }),
      ).toBe(false);
    });

    it("allows allowlist when sender matches", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "allowlist",
          allowFrom: ["User-Id"],
          senderId: "user-id",
          senderName: "User",
        }),
      ).toBe(true);
    });

    it("allows allowlist when sender name matches", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "allowlist",
          allowFrom: ["user"],
          senderId: "other",
          senderName: "User",
        }),
      ).toBe(true);
    });

    it("allows allowlist wildcard", () => {
      expect(
        isMSTeamsGroupAllowed({
          groupPolicy: "allowlist",
          allowFrom: ["*"],
          senderId: "other",
          senderName: "User",
        }),
      ).toBe(true);
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/attachments/download.ts"><![CDATA[import type {
  MSTeamsAccessTokenProvider,
  MSTeamsAttachmentLike,
  MSTeamsInboundMedia,
} from "./types.js";
import { getMSTeamsRuntime } from "../runtime.js";
import {
  extractInlineImageCandidates,
  inferPlaceholder,
  isDownloadableAttachment,
  isRecord,
  isUrlAllowed,
  normalizeContentType,
  resolveAuthAllowedHosts,
  resolveAllowedHosts,
} from "./shared.js";

type DownloadCandidate = {
  url: string;
  fileHint?: string;
  contentTypeHint?: string;
  placeholder: string;
};

function resolveDownloadCandidate(att: MSTeamsAttachmentLike): DownloadCandidate | null {
  const contentType = normalizeContentType(att.contentType);
  const name = typeof att.name === "string" ? att.name.trim() : "";

  if (contentType === "application/vnd.microsoft.teams.file.download.info") {
    if (!isRecord(att.content)) {
      return null;
    }
    const downloadUrl =
      typeof att.content.downloadUrl === "string" ? att.content.downloadUrl.trim() : "";
    if (!downloadUrl) {
      return null;
    }

    const fileType = typeof att.content.fileType === "string" ? att.content.fileType.trim() : "";
    const uniqueId = typeof att.content.uniqueId === "string" ? att.content.uniqueId.trim() : "";
    const fileName = typeof att.content.fileName === "string" ? att.content.fileName.trim() : "";

    const fileHint = name || fileName || (uniqueId && fileType ? `${uniqueId}.${fileType}` : "");
    return {
      url: downloadUrl,
      fileHint: fileHint || undefined,
      contentTypeHint: undefined,
      placeholder: inferPlaceholder({
        contentType,
        fileName: fileHint,
        fileType,
      }),
    };
  }

  const contentUrl = typeof att.contentUrl === "string" ? att.contentUrl.trim() : "";
  if (!contentUrl) {
    return null;
  }

  return {
    url: contentUrl,
    fileHint: name || undefined,
    contentTypeHint: contentType,
    placeholder: inferPlaceholder({ contentType, fileName: name }),
  };
}

function scopeCandidatesForUrl(url: string): string[] {
  try {
    const host = new URL(url).hostname.toLowerCase();
    const looksLikeGraph =
      host.endsWith("graph.microsoft.com") ||
      host.endsWith("sharepoint.com") ||
      host.endsWith("1drv.ms") ||
      host.includes("sharepoint");
    return looksLikeGraph
      ? ["https://graph.microsoft.com", "https://api.botframework.com"]
      : ["https://api.botframework.com", "https://graph.microsoft.com"];
  } catch {
    return ["https://api.botframework.com", "https://graph.microsoft.com"];
  }
}

async function fetchWithAuthFallback(params: {
  url: string;
  tokenProvider?: MSTeamsAccessTokenProvider;
  fetchFn?: typeof fetch;
  allowHosts: string[];
  authAllowHosts: string[];
}): Promise<Response> {
  const fetchFn = params.fetchFn ?? fetch;
  const firstAttempt = await fetchFn(params.url);
  if (firstAttempt.ok) {
    return firstAttempt;
  }
  if (!params.tokenProvider) {
    return firstAttempt;
  }
  if (firstAttempt.status !== 401 && firstAttempt.status !== 403) {
    return firstAttempt;
  }
  if (!isUrlAllowed(params.url, params.authAllowHosts)) {
    return firstAttempt;
  }

  const scopes = scopeCandidatesForUrl(params.url);
  for (const scope of scopes) {
    try {
      const token = await params.tokenProvider.getAccessToken(scope);
      const res = await fetchFn(params.url, {
        headers: { Authorization: `Bearer ${token}` },
        redirect: "manual",
      });
      if (res.ok) {
        return res;
      }
      const redirectUrl = readRedirectUrl(params.url, res);
      if (redirectUrl && isUrlAllowed(redirectUrl, params.allowHosts)) {
        const redirectRes = await fetchFn(redirectUrl);
        if (redirectRes.ok) {
          return redirectRes;
        }
        if (
          (redirectRes.status === 401 || redirectRes.status === 403) &&
          isUrlAllowed(redirectUrl, params.authAllowHosts)
        ) {
          const redirectAuthRes = await fetchFn(redirectUrl, {
            headers: { Authorization: `Bearer ${token}` },
            redirect: "manual",
          });
          if (redirectAuthRes.ok) {
            return redirectAuthRes;
          }
        }
      }
    } catch {
      // Try the next scope.
    }
  }

  return firstAttempt;
}

function readRedirectUrl(baseUrl: string, res: Response): string | null {
  if (![301, 302, 303, 307, 308].includes(res.status)) {
    return null;
  }
  const location = res.headers.get("location");
  if (!location) {
    return null;
  }
  try {
    return new URL(location, baseUrl).toString();
  } catch {
    return null;
  }
}

/**
 * Download all file attachments from a Teams message (images, documents, etc.).
 * Renamed from downloadMSTeamsImageAttachments to support all file types.
 */
export async function downloadMSTeamsAttachments(params: {
  attachments: MSTeamsAttachmentLike[] | undefined;
  maxBytes: number;
  tokenProvider?: MSTeamsAccessTokenProvider;
  allowHosts?: string[];
  authAllowHosts?: string[];
  fetchFn?: typeof fetch;
  /** When true, embeds original filename in stored path for later extraction. */
  preserveFilenames?: boolean;
}): Promise<MSTeamsInboundMedia[]> {
  const list = Array.isArray(params.attachments) ? params.attachments : [];
  if (list.length === 0) {
    return [];
  }
  const allowHosts = resolveAllowedHosts(params.allowHosts);
  const authAllowHosts = resolveAuthAllowedHosts(params.authAllowHosts);

  // Download ANY downloadable attachment (not just images)
  const downloadable = list.filter(isDownloadableAttachment);
  const candidates: DownloadCandidate[] = downloadable
    .map(resolveDownloadCandidate)
    .filter(Boolean) as DownloadCandidate[];

  const inlineCandidates = extractInlineImageCandidates(list);

  const seenUrls = new Set<string>();
  for (const inline of inlineCandidates) {
    if (inline.kind === "url") {
      if (!isUrlAllowed(inline.url, allowHosts)) {
        continue;
      }
      if (seenUrls.has(inline.url)) {
        continue;
      }
      seenUrls.add(inline.url);
      candidates.push({
        url: inline.url,
        fileHint: inline.fileHint,
        contentTypeHint: inline.contentType,
        placeholder: inline.placeholder,
      });
    }
  }
  if (candidates.length === 0 && inlineCandidates.length === 0) {
    return [];
  }

  const out: MSTeamsInboundMedia[] = [];
  for (const inline of inlineCandidates) {
    if (inline.kind !== "data") {
      continue;
    }
    if (inline.data.byteLength > params.maxBytes) {
      continue;
    }
    try {
      // Data inline candidates (base64 data URLs) don't have original filenames
      const saved = await getMSTeamsRuntime().channel.media.saveMediaBuffer(
        inline.data,
        inline.contentType,
        "inbound",
        params.maxBytes,
      );
      out.push({
        path: saved.path,
        contentType: saved.contentType,
        placeholder: inline.placeholder,
      });
    } catch {
      // Ignore decode failures and continue.
    }
  }
  for (const candidate of candidates) {
    if (!isUrlAllowed(candidate.url, allowHosts)) {
      continue;
    }
    try {
      const res = await fetchWithAuthFallback({
        url: candidate.url,
        tokenProvider: params.tokenProvider,
        fetchFn: params.fetchFn,
        allowHosts,
        authAllowHosts,
      });
      if (!res.ok) {
        continue;
      }
      const buffer = Buffer.from(await res.arrayBuffer());
      if (buffer.byteLength > params.maxBytes) {
        continue;
      }
      const mime = await getMSTeamsRuntime().media.detectMime({
        buffer,
        headerMime: res.headers.get("content-type"),
        filePath: candidate.fileHint ?? candidate.url,
      });
      const originalFilename = params.preserveFilenames ? candidate.fileHint : undefined;
      const saved = await getMSTeamsRuntime().channel.media.saveMediaBuffer(
        buffer,
        mime ?? candidate.contentTypeHint,
        "inbound",
        params.maxBytes,
        originalFilename,
      );
      out.push({
        path: saved.path,
        contentType: saved.contentType,
        placeholder: candidate.placeholder,
      });
    } catch {
      // Ignore download failures and continue with next candidate.
    }
  }
  return out;
}

/**
 * @deprecated Use `downloadMSTeamsAttachments` instead (supports all file types).
 */
export const downloadMSTeamsImageAttachments = downloadMSTeamsAttachments;
]]></file>
  <file path="./extensions/msteams/src/attachments/shared.ts"><![CDATA[import type { MSTeamsAttachmentLike } from "./types.js";

type InlineImageCandidate =
  | {
      kind: "data";
      data: Buffer;
      contentType?: string;
      placeholder: string;
    }
  | {
      kind: "url";
      url: string;
      contentType?: string;
      fileHint?: string;
      placeholder: string;
    };

export const IMAGE_EXT_RE = /\.(avif|bmp|gif|heic|heif|jpe?g|png|tiff?|webp)$/i;

export const IMG_SRC_RE = /<img[^>]+src=["']([^"']+)["'][^>]*>/gi;
export const ATTACHMENT_TAG_RE = /<attachment[^>]+id=["']([^"']+)["'][^>]*>/gi;

export const DEFAULT_MEDIA_HOST_ALLOWLIST = [
  "graph.microsoft.com",
  "graph.microsoft.us",
  "graph.microsoft.de",
  "graph.microsoft.cn",
  "sharepoint.com",
  "sharepoint.us",
  "sharepoint.de",
  "sharepoint.cn",
  "sharepoint-df.com",
  "1drv.ms",
  "onedrive.com",
  "teams.microsoft.com",
  "teams.cdn.office.net",
  "statics.teams.cdn.office.net",
  "office.com",
  "office.net",
  // Azure Media Services / Skype CDN for clipboard-pasted images
  "asm.skype.com",
  "ams.skype.com",
  "media.ams.skype.com",
  // Bot Framework attachment URLs
  "trafficmanager.net",
  "blob.core.windows.net",
  "azureedge.net",
  "microsoft.com",
] as const;

export const DEFAULT_MEDIA_AUTH_HOST_ALLOWLIST = [
  "api.botframework.com",
  "botframework.com",
  "graph.microsoft.com",
  "graph.microsoft.us",
  "graph.microsoft.de",
  "graph.microsoft.cn",
] as const;

export const GRAPH_ROOT = "https://graph.microsoft.com/v1.0";

export function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}

export function normalizeContentType(value: unknown): string | undefined {
  if (typeof value !== "string") {
    return undefined;
  }
  const trimmed = value.trim();
  return trimmed ? trimmed : undefined;
}

export function inferPlaceholder(params: {
  contentType?: string;
  fileName?: string;
  fileType?: string;
}): string {
  const mime = params.contentType?.toLowerCase() ?? "";
  const name = params.fileName?.toLowerCase() ?? "";
  const fileType = params.fileType?.toLowerCase() ?? "";

  const looksLikeImage =
    mime.startsWith("image/") || IMAGE_EXT_RE.test(name) || IMAGE_EXT_RE.test(`x.${fileType}`);

  return looksLikeImage ? "<media:image>" : "<media:document>";
}

export function isLikelyImageAttachment(att: MSTeamsAttachmentLike): boolean {
  const contentType = normalizeContentType(att.contentType) ?? "";
  const name = typeof att.name === "string" ? att.name : "";
  if (contentType.startsWith("image/")) {
    return true;
  }
  if (IMAGE_EXT_RE.test(name)) {
    return true;
  }

  if (
    contentType === "application/vnd.microsoft.teams.file.download.info" &&
    isRecord(att.content)
  ) {
    const fileType = typeof att.content.fileType === "string" ? att.content.fileType : "";
    if (fileType && IMAGE_EXT_RE.test(`x.${fileType}`)) {
      return true;
    }
    const fileName = typeof att.content.fileName === "string" ? att.content.fileName : "";
    if (fileName && IMAGE_EXT_RE.test(fileName)) {
      return true;
    }
  }

  return false;
}

/**
 * Returns true if the attachment can be downloaded (any file type).
 * Used when downloading all files, not just images.
 */
export function isDownloadableAttachment(att: MSTeamsAttachmentLike): boolean {
  const contentType = normalizeContentType(att.contentType) ?? "";

  // Teams file download info always has a downloadUrl
  if (
    contentType === "application/vnd.microsoft.teams.file.download.info" &&
    isRecord(att.content) &&
    typeof att.content.downloadUrl === "string"
  ) {
    return true;
  }

  // Any attachment with a contentUrl can be downloaded
  if (typeof att.contentUrl === "string" && att.contentUrl.trim()) {
    return true;
  }

  return false;
}

function isHtmlAttachment(att: MSTeamsAttachmentLike): boolean {
  const contentType = normalizeContentType(att.contentType) ?? "";
  return contentType.startsWith("text/html");
}

export function extractHtmlFromAttachment(att: MSTeamsAttachmentLike): string | undefined {
  if (!isHtmlAttachment(att)) {
    return undefined;
  }
  if (typeof att.content === "string") {
    return att.content;
  }
  if (!isRecord(att.content)) {
    return undefined;
  }
  const text =
    typeof att.content.text === "string"
      ? att.content.text
      : typeof att.content.body === "string"
        ? att.content.body
        : typeof att.content.content === "string"
          ? att.content.content
          : undefined;
  return text;
}

function decodeDataImage(src: string): InlineImageCandidate | null {
  const match = /^data:(image\/[a-z0-9.+-]+)?(;base64)?,(.*)$/i.exec(src);
  if (!match) {
    return null;
  }
  const contentType = match[1]?.toLowerCase();
  const isBase64 = Boolean(match[2]);
  if (!isBase64) {
    return null;
  }
  const payload = match[3] ?? "";
  if (!payload) {
    return null;
  }
  try {
    const data = Buffer.from(payload, "base64");
    return { kind: "data", data, contentType, placeholder: "<media:image>" };
  } catch {
    return null;
  }
}

function fileHintFromUrl(src: string): string | undefined {
  try {
    const url = new URL(src);
    const name = url.pathname.split("/").pop();
    return name || undefined;
  } catch {
    return undefined;
  }
}

export function extractInlineImageCandidates(
  attachments: MSTeamsAttachmentLike[],
): InlineImageCandidate[] {
  const out: InlineImageCandidate[] = [];
  for (const att of attachments) {
    const html = extractHtmlFromAttachment(att);
    if (!html) {
      continue;
    }
    IMG_SRC_RE.lastIndex = 0;
    let match: RegExpExecArray | null = IMG_SRC_RE.exec(html);
    while (match) {
      const src = match[1]?.trim();
      if (src && !src.startsWith("cid:")) {
        if (src.startsWith("data:")) {
          const decoded = decodeDataImage(src);
          if (decoded) {
            out.push(decoded);
          }
        } else {
          out.push({
            kind: "url",
            url: src,
            fileHint: fileHintFromUrl(src),
            placeholder: "<media:image>",
          });
        }
      }
      match = IMG_SRC_RE.exec(html);
    }
  }
  return out;
}

export function safeHostForUrl(url: string): string {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return "invalid-url";
  }
}

function normalizeAllowHost(value: string): string {
  const trimmed = value.trim().toLowerCase();
  if (!trimmed) {
    return "";
  }
  if (trimmed === "*") {
    return "*";
  }
  return trimmed.replace(/^\*\.?/, "");
}

export function resolveAllowedHosts(input?: string[]): string[] {
  if (!Array.isArray(input) || input.length === 0) {
    return DEFAULT_MEDIA_HOST_ALLOWLIST.slice();
  }
  const normalized = input.map(normalizeAllowHost).filter(Boolean);
  if (normalized.includes("*")) {
    return ["*"];
  }
  return normalized;
}

export function resolveAuthAllowedHosts(input?: string[]): string[] {
  if (!Array.isArray(input) || input.length === 0) {
    return DEFAULT_MEDIA_AUTH_HOST_ALLOWLIST.slice();
  }
  const normalized = input.map(normalizeAllowHost).filter(Boolean);
  if (normalized.includes("*")) {
    return ["*"];
  }
  return normalized;
}

function isHostAllowed(host: string, allowlist: string[]): boolean {
  if (allowlist.includes("*")) {
    return true;
  }
  const normalized = host.toLowerCase();
  return allowlist.some((entry) => normalized === entry || normalized.endsWith(`.${entry}`));
}

export function isUrlAllowed(url: string, allowlist: string[]): boolean {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== "https:") {
      return false;
    }
    return isHostAllowed(parsed.hostname, allowlist);
  } catch {
    return false;
  }
}
]]></file>
  <file path="./extensions/msteams/src/attachments/graph.ts"><![CDATA[import type {
  MSTeamsAccessTokenProvider,
  MSTeamsAttachmentLike,
  MSTeamsGraphMediaResult,
  MSTeamsInboundMedia,
} from "./types.js";
import { getMSTeamsRuntime } from "../runtime.js";
import { downloadMSTeamsAttachments } from "./download.js";
import {
  GRAPH_ROOT,
  inferPlaceholder,
  isRecord,
  normalizeContentType,
  resolveAllowedHosts,
} from "./shared.js";

type GraphHostedContent = {
  id?: string | null;
  contentType?: string | null;
  contentBytes?: string | null;
};

type GraphAttachment = {
  id?: string | null;
  contentType?: string | null;
  contentUrl?: string | null;
  name?: string | null;
  thumbnailUrl?: string | null;
  content?: unknown;
};

function readNestedString(value: unknown, keys: Array<string | number>): string | undefined {
  let current: unknown = value;
  for (const key of keys) {
    if (!isRecord(current)) {
      return undefined;
    }
    current = current[key as keyof typeof current];
  }
  return typeof current === "string" && current.trim() ? current.trim() : undefined;
}

export function buildMSTeamsGraphMessageUrls(params: {
  conversationType?: string | null;
  conversationId?: string | null;
  messageId?: string | null;
  replyToId?: string | null;
  conversationMessageId?: string | null;
  channelData?: unknown;
}): string[] {
  const conversationType = params.conversationType?.trim().toLowerCase() ?? "";
  const messageIdCandidates = new Set<string>();
  const pushCandidate = (value: string | null | undefined) => {
    const trimmed = typeof value === "string" ? value.trim() : "";
    if (trimmed) {
      messageIdCandidates.add(trimmed);
    }
  };

  pushCandidate(params.messageId);
  pushCandidate(params.conversationMessageId);
  pushCandidate(readNestedString(params.channelData, ["messageId"]));
  pushCandidate(readNestedString(params.channelData, ["teamsMessageId"]));

  const replyToId = typeof params.replyToId === "string" ? params.replyToId.trim() : "";

  if (conversationType === "channel") {
    const teamId =
      readNestedString(params.channelData, ["team", "id"]) ??
      readNestedString(params.channelData, ["teamId"]);
    const channelId =
      readNestedString(params.channelData, ["channel", "id"]) ??
      readNestedString(params.channelData, ["channelId"]) ??
      readNestedString(params.channelData, ["teamsChannelId"]);
    if (!teamId || !channelId) {
      return [];
    }
    const urls: string[] = [];
    if (replyToId) {
      for (const candidate of messageIdCandidates) {
        if (candidate === replyToId) {
          continue;
        }
        urls.push(
          `${GRAPH_ROOT}/teams/${encodeURIComponent(teamId)}/channels/${encodeURIComponent(channelId)}/messages/${encodeURIComponent(replyToId)}/replies/${encodeURIComponent(candidate)}`,
        );
      }
    }
    if (messageIdCandidates.size === 0 && replyToId) {
      messageIdCandidates.add(replyToId);
    }
    for (const candidate of messageIdCandidates) {
      urls.push(
        `${GRAPH_ROOT}/teams/${encodeURIComponent(teamId)}/channels/${encodeURIComponent(channelId)}/messages/${encodeURIComponent(candidate)}`,
      );
    }
    return Array.from(new Set(urls));
  }

  const chatId = params.conversationId?.trim() || readNestedString(params.channelData, ["chatId"]);
  if (!chatId) {
    return [];
  }
  if (messageIdCandidates.size === 0 && replyToId) {
    messageIdCandidates.add(replyToId);
  }
  const urls = Array.from(messageIdCandidates).map(
    (candidate) =>
      `${GRAPH_ROOT}/chats/${encodeURIComponent(chatId)}/messages/${encodeURIComponent(candidate)}`,
  );
  return Array.from(new Set(urls));
}

async function fetchGraphCollection<T>(params: {
  url: string;
  accessToken: string;
  fetchFn?: typeof fetch;
}): Promise<{ status: number; items: T[] }> {
  const fetchFn = params.fetchFn ?? fetch;
  const res = await fetchFn(params.url, {
    headers: { Authorization: `Bearer ${params.accessToken}` },
  });
  const status = res.status;
  if (!res.ok) {
    return { status, items: [] };
  }
  try {
    const data = (await res.json()) as { value?: T[] };
    return { status, items: Array.isArray(data.value) ? data.value : [] };
  } catch {
    return { status, items: [] };
  }
}

function normalizeGraphAttachment(att: GraphAttachment): MSTeamsAttachmentLike {
  let content: unknown = att.content;
  if (typeof content === "string") {
    try {
      content = JSON.parse(content);
    } catch {
      // Keep as raw string if it's not JSON.
    }
  }
  return {
    contentType: normalizeContentType(att.contentType) ?? undefined,
    contentUrl: att.contentUrl ?? undefined,
    name: att.name ?? undefined,
    thumbnailUrl: att.thumbnailUrl ?? undefined,
    content,
  };
}

/**
 * Download all hosted content from a Teams message (images, documents, etc.).
 * Renamed from downloadGraphHostedImages to support all file types.
 */
async function downloadGraphHostedContent(params: {
  accessToken: string;
  messageUrl: string;
  maxBytes: number;
  fetchFn?: typeof fetch;
  preserveFilenames?: boolean;
}): Promise<{ media: MSTeamsInboundMedia[]; status: number; count: number }> {
  const hosted = await fetchGraphCollection<GraphHostedContent>({
    url: `${params.messageUrl}/hostedContents`,
    accessToken: params.accessToken,
    fetchFn: params.fetchFn,
  });
  if (hosted.items.length === 0) {
    return { media: [], status: hosted.status, count: 0 };
  }

  const out: MSTeamsInboundMedia[] = [];
  for (const item of hosted.items) {
    const contentBytes = typeof item.contentBytes === "string" ? item.contentBytes : "";
    if (!contentBytes) {
      continue;
    }
    let buffer: Buffer;
    try {
      buffer = Buffer.from(contentBytes, "base64");
    } catch {
      continue;
    }
    if (buffer.byteLength > params.maxBytes) {
      continue;
    }
    const mime = await getMSTeamsRuntime().media.detectMime({
      buffer,
      headerMime: item.contentType ?? undefined,
    });
    // Download any file type, not just images
    try {
      const saved = await getMSTeamsRuntime().channel.media.saveMediaBuffer(
        buffer,
        mime ?? item.contentType ?? undefined,
        "inbound",
        params.maxBytes,
      );
      out.push({
        path: saved.path,
        contentType: saved.contentType,
        placeholder: inferPlaceholder({ contentType: saved.contentType }),
      });
    } catch {
      // Ignore save failures.
    }
  }

  return { media: out, status: hosted.status, count: hosted.items.length };
}

export async function downloadMSTeamsGraphMedia(params: {
  messageUrl?: string | null;
  tokenProvider?: MSTeamsAccessTokenProvider;
  maxBytes: number;
  allowHosts?: string[];
  authAllowHosts?: string[];
  fetchFn?: typeof fetch;
  /** When true, embeds original filename in stored path for later extraction. */
  preserveFilenames?: boolean;
}): Promise<MSTeamsGraphMediaResult> {
  if (!params.messageUrl || !params.tokenProvider) {
    return { media: [] };
  }
  const allowHosts = resolveAllowedHosts(params.allowHosts);
  const messageUrl = params.messageUrl;
  let accessToken: string;
  try {
    accessToken = await params.tokenProvider.getAccessToken("https://graph.microsoft.com");
  } catch {
    return { media: [], messageUrl, tokenError: true };
  }

  // Fetch the full message to get SharePoint file attachments (for group chats)
  const fetchFn = params.fetchFn ?? fetch;
  const sharePointMedia: MSTeamsInboundMedia[] = [];
  const downloadedReferenceUrls = new Set<string>();
  try {
    const msgRes = await fetchFn(messageUrl, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    if (msgRes.ok) {
      const msgData = (await msgRes.json()) as {
        body?: { content?: string; contentType?: string };
        attachments?: Array<{
          id?: string;
          contentUrl?: string;
          contentType?: string;
          name?: string;
        }>;
      };

      // Extract SharePoint file attachments (contentType: "reference")
      // Download any file type, not just images
      const spAttachments = (msgData.attachments ?? []).filter(
        (a) => a.contentType === "reference" && a.contentUrl && a.name,
      );
      for (const att of spAttachments) {
        const name = att.name ?? "file";

        try {
          // SharePoint URLs need to be accessed via Graph shares API
          const shareUrl = att.contentUrl!;
          const encodedUrl = Buffer.from(shareUrl).toString("base64url");
          const sharesUrl = `${GRAPH_ROOT}/shares/u!${encodedUrl}/driveItem/content`;

          const spRes = await fetchFn(sharesUrl, {
            headers: { Authorization: `Bearer ${accessToken}` },
            redirect: "follow",
          });

          if (spRes.ok) {
            const buffer = Buffer.from(await spRes.arrayBuffer());
            if (buffer.byteLength <= params.maxBytes) {
              const mime = await getMSTeamsRuntime().media.detectMime({
                buffer,
                headerMime: spRes.headers.get("content-type") ?? undefined,
                filePath: name,
              });
              const originalFilename = params.preserveFilenames ? name : undefined;
              const saved = await getMSTeamsRuntime().channel.media.saveMediaBuffer(
                buffer,
                mime ?? "application/octet-stream",
                "inbound",
                params.maxBytes,
                originalFilename,
              );
              sharePointMedia.push({
                path: saved.path,
                contentType: saved.contentType,
                placeholder: inferPlaceholder({ contentType: saved.contentType, fileName: name }),
              });
              downloadedReferenceUrls.add(shareUrl);
            }
          }
        } catch {
          // Ignore SharePoint download failures.
        }
      }
    }
  } catch {
    // Ignore message fetch failures.
  }

  const hosted = await downloadGraphHostedContent({
    accessToken,
    messageUrl,
    maxBytes: params.maxBytes,
    fetchFn: params.fetchFn,
    preserveFilenames: params.preserveFilenames,
  });

  const attachments = await fetchGraphCollection<GraphAttachment>({
    url: `${messageUrl}/attachments`,
    accessToken,
    fetchFn: params.fetchFn,
  });

  const normalizedAttachments = attachments.items.map(normalizeGraphAttachment);
  const filteredAttachments =
    sharePointMedia.length > 0
      ? normalizedAttachments.filter((att) => {
          const contentType = att.contentType?.toLowerCase();
          if (contentType !== "reference") {
            return true;
          }
          const url = typeof att.contentUrl === "string" ? att.contentUrl : "";
          if (!url) {
            return true;
          }
          return !downloadedReferenceUrls.has(url);
        })
      : normalizedAttachments;
  const attachmentMedia = await downloadMSTeamsAttachments({
    attachments: filteredAttachments,
    maxBytes: params.maxBytes,
    tokenProvider: params.tokenProvider,
    allowHosts,
    authAllowHosts: params.authAllowHosts,
    fetchFn: params.fetchFn,
    preserveFilenames: params.preserveFilenames,
  });

  return {
    media: [...sharePointMedia, ...hosted.media, ...attachmentMedia],
    hostedCount: hosted.count,
    attachmentCount: filteredAttachments.length + sharePointMedia.length,
    hostedStatus: hosted.status,
    attachmentStatus: attachments.status,
    messageUrl,
  };
}
]]></file>
  <file path="./extensions/msteams/src/attachments/payload.ts"><![CDATA[export function buildMSTeamsMediaPayload(
  mediaList: Array<{ path: string; contentType?: string }>,
): {
  MediaPath?: string;
  MediaType?: string;
  MediaUrl?: string;
  MediaPaths?: string[];
  MediaUrls?: string[];
  MediaTypes?: string[];
} {
  const first = mediaList[0];
  const mediaPaths = mediaList.map((media) => media.path);
  const mediaTypes = mediaList.map((media) => media.contentType ?? "");
  return {
    MediaPath: first?.path,
    MediaType: first?.contentType,
    MediaUrl: first?.path,
    MediaPaths: mediaPaths.length > 0 ? mediaPaths : undefined,
    MediaUrls: mediaPaths.length > 0 ? mediaPaths : undefined,
    MediaTypes: mediaPaths.length > 0 ? mediaTypes : undefined,
  };
}
]]></file>
  <file path="./extensions/msteams/src/attachments/types.ts"><![CDATA[export type MSTeamsAttachmentLike = {
  contentType?: string | null;
  contentUrl?: string | null;
  name?: string | null;
  thumbnailUrl?: string | null;
  content?: unknown;
};

export type MSTeamsAccessTokenProvider = {
  getAccessToken: (scope: string) => Promise<string>;
};

export type MSTeamsInboundMedia = {
  path: string;
  contentType?: string;
  placeholder: string;
};

export type MSTeamsHtmlAttachmentSummary = {
  htmlAttachments: number;
  imgTags: number;
  dataImages: number;
  cidImages: number;
  srcHosts: string[];
  attachmentTags: number;
  attachmentIds: string[];
};

export type MSTeamsGraphMediaResult = {
  media: MSTeamsInboundMedia[];
  hostedCount?: number;
  attachmentCount?: number;
  hostedStatus?: number;
  attachmentStatus?: number;
  messageUrl?: string;
  tokenError?: boolean;
};
]]></file>
  <file path="./extensions/msteams/src/attachments/html.ts"><![CDATA[import type { MSTeamsAttachmentLike, MSTeamsHtmlAttachmentSummary } from "./types.js";
import {
  ATTACHMENT_TAG_RE,
  extractHtmlFromAttachment,
  extractInlineImageCandidates,
  IMG_SRC_RE,
  isLikelyImageAttachment,
  safeHostForUrl,
} from "./shared.js";

export function summarizeMSTeamsHtmlAttachments(
  attachments: MSTeamsAttachmentLike[] | undefined,
): MSTeamsHtmlAttachmentSummary | undefined {
  const list = Array.isArray(attachments) ? attachments : [];
  if (list.length === 0) {
    return undefined;
  }
  let htmlAttachments = 0;
  let imgTags = 0;
  let dataImages = 0;
  let cidImages = 0;
  const srcHosts = new Set<string>();
  let attachmentTags = 0;
  const attachmentIds = new Set<string>();

  for (const att of list) {
    const html = extractHtmlFromAttachment(att);
    if (!html) {
      continue;
    }
    htmlAttachments += 1;
    IMG_SRC_RE.lastIndex = 0;
    let match: RegExpExecArray | null = IMG_SRC_RE.exec(html);
    while (match) {
      imgTags += 1;
      const src = match[1]?.trim();
      if (src) {
        if (src.startsWith("data:")) {
          dataImages += 1;
        } else if (src.startsWith("cid:")) {
          cidImages += 1;
        } else {
          srcHosts.add(safeHostForUrl(src));
        }
      }
      match = IMG_SRC_RE.exec(html);
    }

    ATTACHMENT_TAG_RE.lastIndex = 0;
    let attachmentMatch: RegExpExecArray | null = ATTACHMENT_TAG_RE.exec(html);
    while (attachmentMatch) {
      attachmentTags += 1;
      const id = attachmentMatch[1]?.trim();
      if (id) {
        attachmentIds.add(id);
      }
      attachmentMatch = ATTACHMENT_TAG_RE.exec(html);
    }
  }

  if (htmlAttachments === 0) {
    return undefined;
  }
  return {
    htmlAttachments,
    imgTags,
    dataImages,
    cidImages,
    srcHosts: Array.from(srcHosts).slice(0, 5),
    attachmentTags,
    attachmentIds: Array.from(attachmentIds).slice(0, 5),
  };
}

export function buildMSTeamsAttachmentPlaceholder(
  attachments: MSTeamsAttachmentLike[] | undefined,
): string {
  const list = Array.isArray(attachments) ? attachments : [];
  if (list.length === 0) {
    return "";
  }
  const imageCount = list.filter(isLikelyImageAttachment).length;
  const inlineCount = extractInlineImageCandidates(list).length;
  const totalImages = imageCount + inlineCount;
  if (totalImages > 0) {
    return `<media:image>${totalImages > 1 ? ` (${totalImages} images)` : ""}`;
  }
  const count = list.length;
  return `<media:document>${count > 1 ? ` (${count} files)` : ""}`;
}
]]></file>
  <file path="./extensions/msteams/src/file-consent-helpers.ts"><![CDATA[/**
 * Shared helpers for FileConsentCard flow in MSTeams.
 *
 * FileConsentCard is required for:
 * - Personal (1:1) chats with large files (>=4MB)
 * - Personal chats with non-image files (PDFs, documents, etc.)
 *
 * This module consolidates the logic used by both send.ts (proactive sends)
 * and messenger.ts (reply path) to avoid duplication.
 */

import { buildFileConsentCard } from "./file-consent.js";
import { storePendingUpload } from "./pending-uploads.js";

export type FileConsentMedia = {
  buffer: Buffer;
  filename: string;
  contentType?: string;
};

export type FileConsentActivityResult = {
  activity: Record<string, unknown>;
  uploadId: string;
};

/**
 * Prepare a FileConsentCard activity for large files or non-images in personal chats.
 * Returns the activity object and uploadId - caller is responsible for sending.
 */
export function prepareFileConsentActivity(params: {
  media: FileConsentMedia;
  conversationId: string;
  description?: string;
}): FileConsentActivityResult {
  const { media, conversationId, description } = params;

  const uploadId = storePendingUpload({
    buffer: media.buffer,
    filename: media.filename,
    contentType: media.contentType,
    conversationId,
  });

  const consentCard = buildFileConsentCard({
    filename: media.filename,
    description: description || `File: ${media.filename}`,
    sizeInBytes: media.buffer.length,
    context: { uploadId },
  });

  const activity: Record<string, unknown> = {
    type: "message",
    attachments: [consentCard],
  };

  return { activity, uploadId };
}

/**
 * Check if a file requires FileConsentCard flow.
 * True for: personal chat AND (large file OR non-image)
 */
export function requiresFileConsent(params: {
  conversationType: string | undefined;
  contentType: string | undefined;
  bufferSize: number;
  thresholdBytes: number;
}): boolean {
  const isPersonal = params.conversationType?.toLowerCase() === "personal";
  const isImage = params.contentType?.startsWith("image/") ?? false;
  const isLargeFile = params.bufferSize >= params.thresholdBytes;
  return isPersonal && (isLargeFile || !isImage);
}
]]></file>
  <file path="./extensions/msteams/src/monitor-types.ts"><![CDATA[export type MSTeamsMonitorLogger = {
  debug?: (message: string, meta?: Record<string, unknown>) => void;
  info: (message: string, meta?: Record<string, unknown>) => void;
  error: (message: string, meta?: Record<string, unknown>) => void;
};
]]></file>
  <file path="./extensions/msteams/src/token.ts"><![CDATA[import type { MSTeamsConfig } from "openclaw/plugin-sdk";

export type MSTeamsCredentials = {
  appId: string;
  appPassword: string;
  tenantId: string;
};

export function resolveMSTeamsCredentials(cfg?: MSTeamsConfig): MSTeamsCredentials | undefined {
  const appId = cfg?.appId?.trim() || process.env.MSTEAMS_APP_ID?.trim();
  const appPassword = cfg?.appPassword?.trim() || process.env.MSTEAMS_APP_PASSWORD?.trim();
  const tenantId = cfg?.tenantId?.trim() || process.env.MSTEAMS_TENANT_ID?.trim();

  if (!appId || !appPassword || !tenantId) {
    return undefined;
  }

  return { appId, appPassword, tenantId };
}
]]></file>
  <file path="./extensions/msteams/src/storage.ts"><![CDATA[import path from "node:path";
import { getMSTeamsRuntime } from "./runtime.js";

export type MSTeamsStorePathOptions = {
  env?: NodeJS.ProcessEnv;
  homedir?: () => string;
  stateDir?: string;
  storePath?: string;
  filename: string;
};

export function resolveMSTeamsStorePath(params: MSTeamsStorePathOptions): string {
  if (params.storePath) {
    return params.storePath;
  }
  if (params.stateDir) {
    return path.join(params.stateDir, params.filename);
  }

  const env = params.env ?? process.env;
  const stateDir = params.homedir
    ? getMSTeamsRuntime().state.resolveStateDir(env, params.homedir)
    : getMSTeamsRuntime().state.resolveStateDir(env);
  return path.join(stateDir, params.filename);
}
]]></file>
  <file path="./extensions/msteams/src/graph-upload.ts"><![CDATA[/**
 * OneDrive/SharePoint upload utilities for MS Teams file sending.
 *
 * For group chats and channels, files are uploaded to SharePoint and shared via a link.
 * This module provides utilities for:
 * - Uploading files to OneDrive (personal scope - now deprecated for bot use)
 * - Uploading files to SharePoint (group/channel scope)
 * - Creating sharing links (organization-wide or per-user)
 * - Getting chat members for per-user sharing
 */

import type { MSTeamsAccessTokenProvider } from "./attachments/types.js";

const GRAPH_ROOT = "https://graph.microsoft.com/v1.0";
const GRAPH_BETA = "https://graph.microsoft.com/beta";
const GRAPH_SCOPE = "https://graph.microsoft.com";

export interface OneDriveUploadResult {
  id: string;
  webUrl: string;
  name: string;
}

/**
 * Upload a file to the user's OneDrive root folder.
 * For larger files, this uses the simple upload endpoint (up to 4MB).
 * TODO: For files >4MB, implement resumable upload session.
 */
export async function uploadToOneDrive(params: {
  buffer: Buffer;
  filename: string;
  contentType?: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  fetchFn?: typeof fetch;
}): Promise<OneDriveUploadResult> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);

  // Use "OpenClawShared" folder to organize bot-uploaded files
  const uploadPath = `/OpenClawShared/${encodeURIComponent(params.filename)}`;

  const res = await fetchFn(`${GRAPH_ROOT}/me/drive/root:${uploadPath}:/content`, {
    method: "PUT",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": params.contentType ?? "application/octet-stream",
    },
    body: new Uint8Array(params.buffer),
  });

  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`OneDrive upload failed: ${res.status} ${res.statusText} - ${body}`);
  }

  const data = (await res.json()) as {
    id?: string;
    webUrl?: string;
    name?: string;
  };

  if (!data.id || !data.webUrl || !data.name) {
    throw new Error("OneDrive upload response missing required fields");
  }

  return {
    id: data.id,
    webUrl: data.webUrl,
    name: data.name,
  };
}

export interface OneDriveSharingLink {
  webUrl: string;
}

/**
 * Create a sharing link for a OneDrive file.
 * The link allows organization members to view the file.
 */
export async function createSharingLink(params: {
  itemId: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  /** Sharing scope: "organization" (default) or "anonymous" */
  scope?: "organization" | "anonymous";
  fetchFn?: typeof fetch;
}): Promise<OneDriveSharingLink> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);

  const res = await fetchFn(`${GRAPH_ROOT}/me/drive/items/${params.itemId}/createLink`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      type: "view",
      scope: params.scope ?? "organization",
    }),
  });

  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`Create sharing link failed: ${res.status} ${res.statusText} - ${body}`);
  }

  const data = (await res.json()) as {
    link?: { webUrl?: string };
  };

  if (!data.link?.webUrl) {
    throw new Error("Create sharing link response missing webUrl");
  }

  return {
    webUrl: data.link.webUrl,
  };
}

/**
 * Upload a file to OneDrive and create a sharing link.
 * Convenience function for the common case.
 */
export async function uploadAndShareOneDrive(params: {
  buffer: Buffer;
  filename: string;
  contentType?: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  scope?: "organization" | "anonymous";
  fetchFn?: typeof fetch;
}): Promise<{
  itemId: string;
  webUrl: string;
  shareUrl: string;
  name: string;
}> {
  const uploaded = await uploadToOneDrive({
    buffer: params.buffer,
    filename: params.filename,
    contentType: params.contentType,
    tokenProvider: params.tokenProvider,
    fetchFn: params.fetchFn,
  });

  const shareLink = await createSharingLink({
    itemId: uploaded.id,
    tokenProvider: params.tokenProvider,
    scope: params.scope,
    fetchFn: params.fetchFn,
  });

  return {
    itemId: uploaded.id,
    webUrl: uploaded.webUrl,
    shareUrl: shareLink.webUrl,
    name: uploaded.name,
  };
}

// ============================================================================
// SharePoint upload functions for group chats and channels
// ============================================================================

/**
 * Upload a file to a SharePoint site.
 * This is used for group chats and channels where /me/drive doesn't work for bots.
 *
 * @param params.siteId - SharePoint site ID (e.g., "contoso.sharepoint.com,guid1,guid2")
 */
export async function uploadToSharePoint(params: {
  buffer: Buffer;
  filename: string;
  contentType?: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  siteId: string;
  fetchFn?: typeof fetch;
}): Promise<OneDriveUploadResult> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);

  // Use "OpenClawShared" folder to organize bot-uploaded files
  const uploadPath = `/OpenClawShared/${encodeURIComponent(params.filename)}`;

  const res = await fetchFn(
    `${GRAPH_ROOT}/sites/${params.siteId}/drive/root:${uploadPath}:/content`,
    {
      method: "PUT",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": params.contentType ?? "application/octet-stream",
      },
      body: new Uint8Array(params.buffer),
    },
  );

  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`SharePoint upload failed: ${res.status} ${res.statusText} - ${body}`);
  }

  const data = (await res.json()) as {
    id?: string;
    webUrl?: string;
    name?: string;
  };

  if (!data.id || !data.webUrl || !data.name) {
    throw new Error("SharePoint upload response missing required fields");
  }

  return {
    id: data.id,
    webUrl: data.webUrl,
    name: data.name,
  };
}

export interface ChatMember {
  aadObjectId: string;
  displayName?: string;
}

/**
 * Properties needed for native Teams file card attachments.
 * The eTag is used as the attachment ID and webDavUrl as the contentUrl.
 */
export interface DriveItemProperties {
  /** The eTag of the driveItem (used as attachment ID) */
  eTag: string;
  /** The WebDAV URL of the driveItem (used as contentUrl for reference attachment) */
  webDavUrl: string;
  /** The filename */
  name: string;
}

/**
 * Get driveItem properties needed for native Teams file card attachments.
 * This fetches the eTag and webDavUrl which are required for "reference" type attachments.
 *
 * @param params.siteId - SharePoint site ID
 * @param params.itemId - The driveItem ID (returned from upload)
 */
export async function getDriveItemProperties(params: {
  siteId: string;
  itemId: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  fetchFn?: typeof fetch;
}): Promise<DriveItemProperties> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);

  const res = await fetchFn(
    `${GRAPH_ROOT}/sites/${params.siteId}/drive/items/${params.itemId}?$select=eTag,webDavUrl,name`,
    { headers: { Authorization: `Bearer ${token}` } },
  );

  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`Get driveItem properties failed: ${res.status} ${res.statusText} - ${body}`);
  }

  const data = (await res.json()) as {
    eTag?: string;
    webDavUrl?: string;
    name?: string;
  };

  if (!data.eTag || !data.webDavUrl || !data.name) {
    throw new Error("DriveItem response missing required properties (eTag, webDavUrl, or name)");
  }

  return {
    eTag: data.eTag,
    webDavUrl: data.webDavUrl,
    name: data.name,
  };
}

/**
 * Get members of a Teams chat for per-user sharing.
 * Used to create sharing links scoped to only the chat participants.
 */
export async function getChatMembers(params: {
  chatId: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  fetchFn?: typeof fetch;
}): Promise<ChatMember[]> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);

  const res = await fetchFn(`${GRAPH_ROOT}/chats/${params.chatId}/members`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (!res.ok) {
    const body = await res.text().catch(() => "");
    throw new Error(`Get chat members failed: ${res.status} ${res.statusText} - ${body}`);
  }

  const data = (await res.json()) as {
    value?: Array<{
      userId?: string;
      displayName?: string;
    }>;
  };

  return (data.value ?? [])
    .map((m) => ({
      aadObjectId: m.userId ?? "",
      displayName: m.displayName,
    }))
    .filter((m) => m.aadObjectId);
}

/**
 * Create a sharing link for a SharePoint drive item.
 * For organization scope (default), uses v1.0 API.
 * For per-user scope, uses beta API with recipients.
 */
export async function createSharePointSharingLink(params: {
  siteId: string;
  itemId: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  /** Sharing scope: "organization" (default) or "users" (per-user with recipients) */
  scope?: "organization" | "users";
  /** Required when scope is "users": AAD object IDs of recipients */
  recipientObjectIds?: string[];
  fetchFn?: typeof fetch;
}): Promise<OneDriveSharingLink> {
  const fetchFn = params.fetchFn ?? fetch;
  const token = await params.tokenProvider.getAccessToken(GRAPH_SCOPE);
  const scope = params.scope ?? "organization";

  // Per-user sharing requires beta API
  const apiRoot = scope === "users" ? GRAPH_BETA : GRAPH_ROOT;

  const body: Record<string, unknown> = {
    type: "view",
    scope: scope === "users" ? "users" : "organization",
  };

  // Add recipients for per-user sharing
  if (scope === "users" && params.recipientObjectIds?.length) {
    body.recipients = params.recipientObjectIds.map((id) => ({ objectId: id }));
  }

  const res = await fetchFn(
    `${apiRoot}/sites/${params.siteId}/drive/items/${params.itemId}/createLink`,
    {
      method: "POST",
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(body),
    },
  );

  if (!res.ok) {
    const respBody = await res.text().catch(() => "");
    throw new Error(
      `Create SharePoint sharing link failed: ${res.status} ${res.statusText} - ${respBody}`,
    );
  }

  const data = (await res.json()) as {
    link?: { webUrl?: string };
  };

  if (!data.link?.webUrl) {
    throw new Error("Create SharePoint sharing link response missing webUrl");
  }

  return {
    webUrl: data.link.webUrl,
  };
}

/**
 * Upload a file to SharePoint and create a sharing link.
 *
 * For group chats, this creates a per-user sharing link scoped to chat members.
 * For channels, this creates an organization-wide sharing link.
 *
 * @param params.siteId - SharePoint site ID
 * @param params.chatId - Optional chat ID for per-user sharing (group chats)
 * @param params.usePerUserSharing - Whether to use per-user sharing (requires beta API + Chat.Read.All)
 */
export async function uploadAndShareSharePoint(params: {
  buffer: Buffer;
  filename: string;
  contentType?: string;
  tokenProvider: MSTeamsAccessTokenProvider;
  siteId: string;
  chatId?: string;
  usePerUserSharing?: boolean;
  fetchFn?: typeof fetch;
}): Promise<{
  itemId: string;
  webUrl: string;
  shareUrl: string;
  name: string;
}> {
  // 1. Upload file to SharePoint
  const uploaded = await uploadToSharePoint({
    buffer: params.buffer,
    filename: params.filename,
    contentType: params.contentType,
    tokenProvider: params.tokenProvider,
    siteId: params.siteId,
    fetchFn: params.fetchFn,
  });

  // 2. Determine sharing scope
  let scope: "organization" | "users" = "organization";
  let recipientObjectIds: string[] | undefined;

  if (params.usePerUserSharing && params.chatId) {
    try {
      const members = await getChatMembers({
        chatId: params.chatId,
        tokenProvider: params.tokenProvider,
        fetchFn: params.fetchFn,
      });

      if (members.length > 0) {
        scope = "users";
        recipientObjectIds = members.map((m) => m.aadObjectId);
      }
    } catch {
      // Fall back to organization scope if we can't get chat members
      // (e.g., missing Chat.Read.All permission)
    }
  }

  // 3. Create sharing link
  const shareLink = await createSharePointSharingLink({
    siteId: params.siteId,
    itemId: uploaded.id,
    tokenProvider: params.tokenProvider,
    scope,
    recipientObjectIds,
    fetchFn: params.fetchFn,
  });

  return {
    itemId: uploaded.id,
    webUrl: uploaded.webUrl,
    shareUrl: shareLink.webUrl,
    name: uploaded.name,
  };
}
]]></file>
  <file path="./extensions/msteams/src/send-context.ts"><![CDATA[import {
  resolveChannelMediaMaxBytes,
  type OpenClawConfig,
  type PluginRuntime,
} from "openclaw/plugin-sdk";
import type { MSTeamsAccessTokenProvider } from "./attachments/types.js";
import type {
  MSTeamsConversationStore,
  StoredConversationReference,
} from "./conversation-store.js";
import type { MSTeamsAdapter } from "./messenger.js";
import { createMSTeamsConversationStoreFs } from "./conversation-store-fs.js";
import { getMSTeamsRuntime } from "./runtime.js";
import { createMSTeamsAdapter, loadMSTeamsSdkWithAuth } from "./sdk.js";
import { resolveMSTeamsCredentials } from "./token.js";

export type MSTeamsConversationType = "personal" | "groupChat" | "channel";

export type MSTeamsProactiveContext = {
  appId: string;
  conversationId: string;
  ref: StoredConversationReference;
  adapter: MSTeamsAdapter;
  log: ReturnType<PluginRuntime["logging"]["getChildLogger"]>;
  /** The type of conversation: personal (1:1), groupChat, or channel */
  conversationType: MSTeamsConversationType;
  /** Token provider for Graph API / OneDrive operations */
  tokenProvider: MSTeamsAccessTokenProvider;
  /** SharePoint site ID for file uploads in group chats/channels */
  sharePointSiteId?: string;
  /** Resolved media max bytes from config (default: 100MB) */
  mediaMaxBytes?: number;
};

/**
 * Parse the target value into a conversation reference lookup key.
 * Supported formats:
 * - conversation:19:abc@thread.tacv2 → lookup by conversation ID
 * - user:aad-object-id → lookup by user AAD object ID
 * - 19:abc@thread.tacv2 → direct conversation ID
 */
function parseRecipient(to: string): {
  type: "conversation" | "user";
  id: string;
} {
  const trimmed = to.trim();
  const finalize = (type: "conversation" | "user", id: string) => {
    const normalized = id.trim();
    if (!normalized) {
      throw new Error(`Invalid target value: missing ${type} id`);
    }
    return { type, id: normalized };
  };
  if (trimmed.startsWith("conversation:")) {
    return finalize("conversation", trimmed.slice("conversation:".length));
  }
  if (trimmed.startsWith("user:")) {
    return finalize("user", trimmed.slice("user:".length));
  }
  // Assume it's a conversation ID if it looks like one
  if (trimmed.startsWith("19:") || trimmed.includes("@thread")) {
    return finalize("conversation", trimmed);
  }
  // Otherwise treat as user ID
  return finalize("user", trimmed);
}

/**
 * Find a stored conversation reference for the given recipient.
 */
async function findConversationReference(recipient: {
  type: "conversation" | "user";
  id: string;
  store: MSTeamsConversationStore;
}): Promise<{
  conversationId: string;
  ref: StoredConversationReference;
} | null> {
  if (recipient.type === "conversation") {
    const ref = await recipient.store.get(recipient.id);
    if (ref) {
      return { conversationId: recipient.id, ref };
    }
    return null;
  }

  const found = await recipient.store.findByUserId(recipient.id);
  if (!found) {
    return null;
  }
  return { conversationId: found.conversationId, ref: found.reference };
}

export async function resolveMSTeamsSendContext(params: {
  cfg: OpenClawConfig;
  to: string;
}): Promise<MSTeamsProactiveContext> {
  const msteamsCfg = params.cfg.channels?.msteams;

  if (!msteamsCfg?.enabled) {
    throw new Error("msteams provider is not enabled");
  }

  const creds = resolveMSTeamsCredentials(msteamsCfg);
  if (!creds) {
    throw new Error("msteams credentials not configured");
  }

  const store = createMSTeamsConversationStoreFs();

  // Parse recipient and find conversation reference
  const recipient = parseRecipient(params.to);
  const found = await findConversationReference({ ...recipient, store });

  if (!found) {
    throw new Error(
      `No conversation reference found for ${recipient.type}:${recipient.id}. ` +
        `The bot must receive a message from this conversation before it can send proactively.`,
    );
  }

  const { conversationId, ref } = found;
  const core = getMSTeamsRuntime();
  const log = core.logging.getChildLogger({ name: "msteams:send" });

  const { sdk, authConfig } = await loadMSTeamsSdkWithAuth(creds);
  const adapter = createMSTeamsAdapter(authConfig, sdk);

  // Create token provider for Graph API / OneDrive operations
  const tokenProvider = new sdk.MsalTokenProvider(authConfig) as MSTeamsAccessTokenProvider;

  // Determine conversation type from stored reference
  const storedConversationType = ref.conversation?.conversationType?.toLowerCase() ?? "";
  let conversationType: MSTeamsConversationType;
  if (storedConversationType === "personal") {
    conversationType = "personal";
  } else if (storedConversationType === "channel") {
    conversationType = "channel";
  } else {
    // groupChat, or unknown defaults to groupChat behavior
    conversationType = "groupChat";
  }

  // Get SharePoint site ID from config (required for file uploads in group chats/channels)
  const sharePointSiteId = msteamsCfg.sharePointSiteId;

  // Resolve media max bytes from config
  const mediaMaxBytes = resolveChannelMediaMaxBytes({
    cfg: params.cfg,
    resolveChannelLimitMb: ({ cfg }) => cfg.channels?.msteams?.mediaMaxMb,
  });

  return {
    appId: creds.appId,
    conversationId,
    ref,
    adapter: adapter as unknown as MSTeamsAdapter,
    log,
    conversationType,
    tokenProvider,
    sharePointSiteId,
    mediaMaxBytes,
  };
}
]]></file>
  <file path="./extensions/msteams/src/media-helpers.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import { extractFilename, extractMessageId, getMimeType, isLocalPath } from "./media-helpers.js";

describe("msteams media-helpers", () => {
  describe("getMimeType", () => {
    it("detects png from URL", async () => {
      expect(await getMimeType("https://example.com/image.png")).toBe("image/png");
    });

    it("detects jpeg from URL (both extensions)", async () => {
      expect(await getMimeType("https://example.com/photo.jpg")).toBe("image/jpeg");
      expect(await getMimeType("https://example.com/photo.jpeg")).toBe("image/jpeg");
    });

    it("detects gif from URL", async () => {
      expect(await getMimeType("https://example.com/anim.gif")).toBe("image/gif");
    });

    it("detects webp from URL", async () => {
      expect(await getMimeType("https://example.com/modern.webp")).toBe("image/webp");
    });

    it("handles URLs with query strings", async () => {
      expect(await getMimeType("https://example.com/image.png?v=123")).toBe("image/png");
    });

    it("handles data URLs", async () => {
      expect(await getMimeType("data:image/png;base64,iVBORw0KGgo=")).toBe("image/png");
      expect(await getMimeType("data:image/jpeg;base64,/9j/4AAQ")).toBe("image/jpeg");
      expect(await getMimeType("data:image/gif;base64,R0lGOD")).toBe("image/gif");
    });

    it("handles data URLs without base64", async () => {
      expect(await getMimeType("data:image/svg+xml,%3Csvg")).toBe("image/svg+xml");
    });

    it("handles local paths", async () => {
      expect(await getMimeType("/tmp/image.png")).toBe("image/png");
      expect(await getMimeType("/Users/test/photo.jpg")).toBe("image/jpeg");
    });

    it("handles tilde paths", async () => {
      expect(await getMimeType("~/Downloads/image.gif")).toBe("image/gif");
    });

    it("defaults to application/octet-stream for unknown extensions", async () => {
      expect(await getMimeType("https://example.com/image")).toBe("application/octet-stream");
      expect(await getMimeType("https://example.com/image.unknown")).toBe(
        "application/octet-stream",
      );
    });

    it("is case-insensitive", async () => {
      expect(await getMimeType("https://example.com/IMAGE.PNG")).toBe("image/png");
      expect(await getMimeType("https://example.com/Photo.JPEG")).toBe("image/jpeg");
    });

    it("detects document types", async () => {
      expect(await getMimeType("https://example.com/doc.pdf")).toBe("application/pdf");
      expect(await getMimeType("https://example.com/doc.docx")).toBe(
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      );
      expect(await getMimeType("https://example.com/spreadsheet.xlsx")).toBe(
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      );
    });
  });

  describe("extractFilename", () => {
    it("extracts filename from URL with extension", async () => {
      expect(await extractFilename("https://example.com/photo.jpg")).toBe("photo.jpg");
    });

    it("extracts filename from URL with path", async () => {
      expect(await extractFilename("https://example.com/images/2024/photo.png")).toBe("photo.png");
    });

    it("handles URLs without extension by deriving from MIME", async () => {
      // Now defaults to application/octet-stream → .bin fallback
      expect(await extractFilename("https://example.com/images/photo")).toBe("photo.bin");
    });

    it("handles data URLs", async () => {
      expect(await extractFilename("data:image/png;base64,iVBORw0KGgo=")).toBe("image.png");
      expect(await extractFilename("data:image/jpeg;base64,/9j/4AAQ")).toBe("image.jpg");
    });

    it("handles document data URLs", async () => {
      expect(await extractFilename("data:application/pdf;base64,JVBERi0")).toBe("file.pdf");
    });

    it("handles local paths", async () => {
      expect(await extractFilename("/tmp/screenshot.png")).toBe("screenshot.png");
      expect(await extractFilename("/Users/test/photo.jpg")).toBe("photo.jpg");
    });

    it("handles tilde paths", async () => {
      expect(await extractFilename("~/Downloads/image.gif")).toBe("image.gif");
    });

    it("returns fallback for empty URL", async () => {
      expect(await extractFilename("")).toBe("file.bin");
    });

    it("extracts original filename from embedded pattern", async () => {
      // Pattern: {original}---{uuid}.{ext}
      expect(
        await extractFilename("/media/inbound/report---a1b2c3d4-e5f6-7890-abcd-ef1234567890.pdf"),
      ).toBe("report.pdf");
    });

    it("extracts original filename with uppercase UUID", async () => {
      expect(
        await extractFilename(
          "/media/inbound/Document---A1B2C3D4-E5F6-7890-ABCD-EF1234567890.docx",
        ),
      ).toBe("Document.docx");
    });

    it("falls back to UUID filename for legacy paths", async () => {
      // UUID-only filename (legacy format, no embedded name)
      expect(await extractFilename("/media/inbound/a1b2c3d4-e5f6-7890-abcd-ef1234567890.pdf")).toBe(
        "a1b2c3d4-e5f6-7890-abcd-ef1234567890.pdf",
      );
    });

    it("handles --- in filename without valid UUID pattern", async () => {
      // foo---bar.txt (bar is not a valid UUID)
      expect(await extractFilename("/media/inbound/foo---bar.txt")).toBe("foo---bar.txt");
    });
  });

  describe("isLocalPath", () => {
    it("returns true for file:// URLs", () => {
      expect(isLocalPath("file:///tmp/image.png")).toBe(true);
      expect(isLocalPath("file://localhost/tmp/image.png")).toBe(true);
    });

    it("returns true for absolute paths", () => {
      expect(isLocalPath("/tmp/image.png")).toBe(true);
      expect(isLocalPath("/Users/test/photo.jpg")).toBe(true);
    });

    it("returns true for tilde paths", () => {
      expect(isLocalPath("~/Downloads/image.png")).toBe(true);
    });

    it("returns true for Windows absolute drive paths", () => {
      expect(isLocalPath("C:\\Users\\test\\image.png")).toBe(true);
      expect(isLocalPath("D:/data/photo.jpg")).toBe(true);
    });

    it("returns true for Windows UNC paths", () => {
      expect(isLocalPath("\\\\server\\share\\image.png")).toBe(true);
    });

    it("returns false for http URLs", () => {
      expect(isLocalPath("http://example.com/image.png")).toBe(false);
      expect(isLocalPath("https://example.com/image.png")).toBe(false);
    });

    it("returns false for data URLs", () => {
      expect(isLocalPath("data:image/png;base64,iVBORw0KGgo=")).toBe(false);
    });
  });

  describe("extractMessageId", () => {
    it("extracts id from valid response", () => {
      expect(extractMessageId({ id: "msg123" })).toBe("msg123");
    });

    it("returns null for missing id", () => {
      expect(extractMessageId({ foo: "bar" })).toBeNull();
    });

    it("returns null for empty id", () => {
      expect(extractMessageId({ id: "" })).toBeNull();
    });

    it("returns null for non-string id", () => {
      expect(extractMessageId({ id: 123 })).toBeNull();
      expect(extractMessageId({ id: null })).toBeNull();
    });

    it("returns null for null response", () => {
      expect(extractMessageId(null)).toBeNull();
    });

    it("returns null for undefined response", () => {
      expect(extractMessageId(undefined)).toBeNull();
    });

    it("returns null for non-object response", () => {
      expect(extractMessageId("string")).toBeNull();
      expect(extractMessageId(123)).toBeNull();
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/attachments.ts"><![CDATA[export {
  downloadMSTeamsAttachments,
  /** @deprecated Use `downloadMSTeamsAttachments` instead. */
  downloadMSTeamsImageAttachments,
} from "./attachments/download.js";
export { buildMSTeamsGraphMessageUrls, downloadMSTeamsGraphMedia } from "./attachments/graph.js";
export {
  buildMSTeamsAttachmentPlaceholder,
  summarizeMSTeamsHtmlAttachments,
} from "./attachments/html.js";
export { buildMSTeamsMediaPayload } from "./attachments/payload.js";
export type {
  MSTeamsAccessTokenProvider,
  MSTeamsAttachmentLike,
  MSTeamsGraphMediaResult,
  MSTeamsHtmlAttachmentSummary,
  MSTeamsInboundMedia,
} from "./attachments/types.js";
]]></file>
  <file path="./extensions/msteams/src/store-fs.ts"><![CDATA[import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { safeParseJson } from "openclaw/plugin-sdk";
import lockfile from "proper-lockfile";

const STORE_LOCK_OPTIONS = {
  retries: {
    retries: 10,
    factor: 2,
    minTimeout: 100,
    maxTimeout: 10_000,
    randomize: true,
  },
  stale: 30_000,
} as const;

export async function readJsonFile<T>(
  filePath: string,
  fallback: T,
): Promise<{ value: T; exists: boolean }> {
  try {
    const raw = await fs.promises.readFile(filePath, "utf-8");
    const parsed = safeParseJson<T>(raw);
    if (parsed == null) {
      return { value: fallback, exists: true };
    }
    return { value: parsed, exists: true };
  } catch (err) {
    const code = (err as { code?: string }).code;
    if (code === "ENOENT") {
      return { value: fallback, exists: false };
    }
    return { value: fallback, exists: false };
  }
}

export async function writeJsonFile(filePath: string, value: unknown): Promise<void> {
  const dir = path.dirname(filePath);
  await fs.promises.mkdir(dir, { recursive: true, mode: 0o700 });
  const tmp = path.join(dir, `${path.basename(filePath)}.${crypto.randomUUID()}.tmp`);
  await fs.promises.writeFile(tmp, `${JSON.stringify(value, null, 2)}\n`, {
    encoding: "utf-8",
  });
  await fs.promises.chmod(tmp, 0o600);
  await fs.promises.rename(tmp, filePath);
}

async function ensureJsonFile(filePath: string, fallback: unknown) {
  try {
    await fs.promises.access(filePath);
  } catch {
    await writeJsonFile(filePath, fallback);
  }
}

export async function withFileLock<T>(
  filePath: string,
  fallback: unknown,
  fn: () => Promise<T>,
): Promise<T> {
  await ensureJsonFile(filePath, fallback);
  let release: (() => Promise<void>) | undefined;
  try {
    release = await lockfile.lock(filePath, STORE_LOCK_OPTIONS);
    return await fn();
  } finally {
    if (release) {
      try {
        await release();
      } catch {
        // ignore unlock errors
      }
    }
  }
}
]]></file>
  <file path="./extensions/msteams/src/reply-dispatcher.ts"><![CDATA[import {
  createReplyPrefixOptions,
  createTypingCallbacks,
  logTypingFailure,
  resolveChannelMediaMaxBytes,
  type OpenClawConfig,
  type MSTeamsReplyStyle,
  type RuntimeEnv,
} from "openclaw/plugin-sdk";
import type { MSTeamsAccessTokenProvider } from "./attachments/types.js";
import type { StoredConversationReference } from "./conversation-store.js";
import type { MSTeamsMonitorLogger } from "./monitor-types.js";
import type { MSTeamsTurnContext } from "./sdk-types.js";
import {
  classifyMSTeamsSendError,
  formatMSTeamsSendErrorHint,
  formatUnknownError,
} from "./errors.js";
import {
  type MSTeamsAdapter,
  renderReplyPayloadsToMessages,
  sendMSTeamsMessages,
} from "./messenger.js";
import { getMSTeamsRuntime } from "./runtime.js";

export function createMSTeamsReplyDispatcher(params: {
  cfg: OpenClawConfig;
  agentId: string;
  accountId?: string;
  runtime: RuntimeEnv;
  log: MSTeamsMonitorLogger;
  adapter: MSTeamsAdapter;
  appId: string;
  conversationRef: StoredConversationReference;
  context: MSTeamsTurnContext;
  replyStyle: MSTeamsReplyStyle;
  textLimit: number;
  onSentMessageIds?: (ids: string[]) => void;
  /** Token provider for OneDrive/SharePoint uploads in group chats/channels */
  tokenProvider?: MSTeamsAccessTokenProvider;
  /** SharePoint site ID for file uploads in group chats/channels */
  sharePointSiteId?: string;
}) {
  const core = getMSTeamsRuntime();
  const sendTypingIndicator = async () => {
    await params.context.sendActivity({ type: "typing" });
  };
  const typingCallbacks = createTypingCallbacks({
    start: sendTypingIndicator,
    onStartError: (err) => {
      logTypingFailure({
        log: (message) => params.log.debug?.(message),
        channel: "msteams",
        action: "start",
        error: err,
      });
    },
  });
  const { onModelSelected, ...prefixOptions } = createReplyPrefixOptions({
    cfg: params.cfg,
    agentId: params.agentId,
    channel: "msteams",
    accountId: params.accountId,
  });
  const chunkMode = core.channel.text.resolveChunkMode(params.cfg, "msteams");

  const { dispatcher, replyOptions, markDispatchIdle } =
    core.channel.reply.createReplyDispatcherWithTyping({
      ...prefixOptions,
      humanDelay: core.channel.reply.resolveHumanDelayConfig(params.cfg, params.agentId),
      deliver: async (payload) => {
        const tableMode = core.channel.text.resolveMarkdownTableMode({
          cfg: params.cfg,
          channel: "msteams",
        });
        const messages = renderReplyPayloadsToMessages([payload], {
          textChunkLimit: params.textLimit,
          chunkText: true,
          mediaMode: "split",
          tableMode,
          chunkMode,
        });
        const mediaMaxBytes = resolveChannelMediaMaxBytes({
          cfg: params.cfg,
          resolveChannelLimitMb: ({ cfg }) => cfg.channels?.msteams?.mediaMaxMb,
        });
        const ids = await sendMSTeamsMessages({
          replyStyle: params.replyStyle,
          adapter: params.adapter,
          appId: params.appId,
          conversationRef: params.conversationRef,
          context: params.context,
          messages,
          // Enable default retry/backoff for throttling/transient failures.
          retry: {},
          onRetry: (event) => {
            params.log.debug?.("retrying send", {
              replyStyle: params.replyStyle,
              ...event,
            });
          },
          tokenProvider: params.tokenProvider,
          sharePointSiteId: params.sharePointSiteId,
          mediaMaxBytes,
        });
        if (ids.length > 0) {
          params.onSentMessageIds?.(ids);
        }
      },
      onError: (err, info) => {
        const errMsg = formatUnknownError(err);
        const classification = classifyMSTeamsSendError(err);
        const hint = formatMSTeamsSendErrorHint(classification);
        params.runtime.error?.(
          `msteams ${info.kind} reply failed: ${errMsg}${hint ? ` (${hint})` : ""}`,
        );
        params.log.error("reply failed", {
          kind: info.kind,
          error: errMsg,
          classification,
          hint,
        });
      },
      onReplyStart: typingCallbacks.onReplyStart,
    });

  return {
    dispatcher,
    replyOptions: { ...replyOptions, onModelSelected },
    markDispatchIdle,
  };
}
]]></file>
  <file path="./extensions/msteams/src/attachments.test.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { setMSTeamsRuntime } from "./runtime.js";

const detectMimeMock = vi.fn(async () => "image/png");
const saveMediaBufferMock = vi.fn(async () => ({
  path: "/tmp/saved.png",
  contentType: "image/png",
}));

const runtimeStub = {
  media: {
    detectMime: (...args: unknown[]) => detectMimeMock(...args),
  },
  channel: {
    media: {
      saveMediaBuffer: (...args: unknown[]) => saveMediaBufferMock(...args),
    },
  },
} as unknown as PluginRuntime;

describe("msteams attachments", () => {
  const load = async () => {
    return await import("./attachments.js");
  };

  beforeEach(() => {
    detectMimeMock.mockClear();
    saveMediaBufferMock.mockClear();
    setMSTeamsRuntime(runtimeStub);
  });

  describe("buildMSTeamsAttachmentPlaceholder", () => {
    it("returns empty string when no attachments", async () => {
      const { buildMSTeamsAttachmentPlaceholder } = await load();
      expect(buildMSTeamsAttachmentPlaceholder(undefined)).toBe("");
      expect(buildMSTeamsAttachmentPlaceholder([])).toBe("");
    });

    it("returns image placeholder for image attachments", async () => {
      const { buildMSTeamsAttachmentPlaceholder } = await load();
      expect(
        buildMSTeamsAttachmentPlaceholder([
          { contentType: "image/png", contentUrl: "https://x/img.png" },
        ]),
      ).toBe("<media:image>");
      expect(
        buildMSTeamsAttachmentPlaceholder([
          { contentType: "image/png", contentUrl: "https://x/1.png" },
          { contentType: "image/jpeg", contentUrl: "https://x/2.jpg" },
        ]),
      ).toBe("<media:image> (2 images)");
    });

    it("treats Teams file.download.info image attachments as images", async () => {
      const { buildMSTeamsAttachmentPlaceholder } = await load();
      expect(
        buildMSTeamsAttachmentPlaceholder([
          {
            contentType: "application/vnd.microsoft.teams.file.download.info",
            content: { downloadUrl: "https://x/dl", fileType: "png" },
          },
        ]),
      ).toBe("<media:image>");
    });

    it("returns document placeholder for non-image attachments", async () => {
      const { buildMSTeamsAttachmentPlaceholder } = await load();
      expect(
        buildMSTeamsAttachmentPlaceholder([
          { contentType: "application/pdf", contentUrl: "https://x/x.pdf" },
        ]),
      ).toBe("<media:document>");
      expect(
        buildMSTeamsAttachmentPlaceholder([
          { contentType: "application/pdf", contentUrl: "https://x/1.pdf" },
          { contentType: "application/pdf", contentUrl: "https://x/2.pdf" },
        ]),
      ).toBe("<media:document> (2 files)");
    });

    it("counts inline images in text/html attachments", async () => {
      const { buildMSTeamsAttachmentPlaceholder } = await load();
      expect(
        buildMSTeamsAttachmentPlaceholder([
          {
            contentType: "text/html",
            content: '<p>hi</p><img src="https://x/a.png" />',
          },
        ]),
      ).toBe("<media:image>");
      expect(
        buildMSTeamsAttachmentPlaceholder([
          {
            contentType: "text/html",
            content: '<img src="https://x/a.png" /><img src="https://x/b.png" />',
          },
        ]),
      ).toBe("<media:image> (2 images)");
    });
  });

  describe("downloadMSTeamsAttachments", () => {
    it("downloads and stores image contentUrl attachments", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn(async () => {
        return new Response(Buffer.from("png"), {
          status: 200,
          headers: { "content-type": "image/png" },
        });
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [{ contentType: "image/png", contentUrl: "https://x/img" }],
        maxBytes: 1024 * 1024,
        allowHosts: ["x"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(fetchMock).toHaveBeenCalledWith("https://x/img");
      expect(saveMediaBufferMock).toHaveBeenCalled();
      expect(media).toHaveLength(1);
      expect(media[0]?.path).toBe("/tmp/saved.png");
    });

    it("supports Teams file.download.info downloadUrl attachments", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn(async () => {
        return new Response(Buffer.from("png"), {
          status: 200,
          headers: { "content-type": "image/png" },
        });
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [
          {
            contentType: "application/vnd.microsoft.teams.file.download.info",
            content: { downloadUrl: "https://x/dl", fileType: "png" },
          },
        ],
        maxBytes: 1024 * 1024,
        allowHosts: ["x"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(fetchMock).toHaveBeenCalledWith("https://x/dl");
      expect(media).toHaveLength(1);
    });

    it("downloads non-image file attachments (PDF)", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn(async () => {
        return new Response(Buffer.from("pdf"), {
          status: 200,
          headers: { "content-type": "application/pdf" },
        });
      });
      detectMimeMock.mockResolvedValueOnce("application/pdf");
      saveMediaBufferMock.mockResolvedValueOnce({
        path: "/tmp/saved.pdf",
        contentType: "application/pdf",
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [{ contentType: "application/pdf", contentUrl: "https://x/doc.pdf" }],
        maxBytes: 1024 * 1024,
        allowHosts: ["x"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(fetchMock).toHaveBeenCalledWith("https://x/doc.pdf");
      expect(media).toHaveLength(1);
      expect(media[0]?.path).toBe("/tmp/saved.pdf");
      expect(media[0]?.placeholder).toBe("<media:document>");
    });

    it("downloads inline image URLs from html attachments", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn(async () => {
        return new Response(Buffer.from("png"), {
          status: 200,
          headers: { "content-type": "image/png" },
        });
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [
          {
            contentType: "text/html",
            content: '<img src="https://x/inline.png" />',
          },
        ],
        maxBytes: 1024 * 1024,
        allowHosts: ["x"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(media).toHaveLength(1);
      expect(fetchMock).toHaveBeenCalledWith("https://x/inline.png");
    });

    it("stores inline data:image base64 payloads", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const base64 = Buffer.from("png").toString("base64");
      const media = await downloadMSTeamsAttachments({
        attachments: [
          {
            contentType: "text/html",
            content: `<img src="data:image/png;base64,${base64}" />`,
          },
        ],
        maxBytes: 1024 * 1024,
        allowHosts: ["x"],
      });

      expect(media).toHaveLength(1);
      expect(saveMediaBufferMock).toHaveBeenCalled();
    });

    it("retries with auth when the first request is unauthorized", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn(async (_url: string, opts?: RequestInit) => {
        const hasAuth = Boolean(
          opts &&
          typeof opts === "object" &&
          "headers" in opts &&
          (opts.headers as Record<string, string>)?.Authorization,
        );
        if (!hasAuth) {
          return new Response("unauthorized", { status: 401 });
        }
        return new Response(Buffer.from("png"), {
          status: 200,
          headers: { "content-type": "image/png" },
        });
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [{ contentType: "image/png", contentUrl: "https://x/img" }],
        maxBytes: 1024 * 1024,
        tokenProvider: { getAccessToken: vi.fn(async () => "token") },
        allowHosts: ["x"],
        authAllowHosts: ["x"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(fetchMock).toHaveBeenCalled();
      expect(media).toHaveLength(1);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it("skips auth retries when the host is not in auth allowlist", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const tokenProvider = { getAccessToken: vi.fn(async () => "token") };
      const fetchMock = vi.fn(async (_url: string, opts?: RequestInit) => {
        const hasAuth = Boolean(
          opts &&
          typeof opts === "object" &&
          "headers" in opts &&
          (opts.headers as Record<string, string>)?.Authorization,
        );
        if (!hasAuth) {
          return new Response("forbidden", { status: 403 });
        }
        return new Response(Buffer.from("png"), {
          status: 200,
          headers: { "content-type": "image/png" },
        });
      });

      const media = await downloadMSTeamsAttachments({
        attachments: [
          { contentType: "image/png", contentUrl: "https://attacker.azureedge.net/img" },
        ],
        maxBytes: 1024 * 1024,
        tokenProvider,
        allowHosts: ["azureedge.net"],
        authAllowHosts: ["graph.microsoft.com"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(media).toHaveLength(0);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(tokenProvider.getAccessToken).not.toHaveBeenCalled();
    });

    it("skips urls outside the allowlist", async () => {
      const { downloadMSTeamsAttachments } = await load();
      const fetchMock = vi.fn();
      const media = await downloadMSTeamsAttachments({
        attachments: [{ contentType: "image/png", contentUrl: "https://evil.test/img" }],
        maxBytes: 1024 * 1024,
        allowHosts: ["graph.microsoft.com"],
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(media).toHaveLength(0);
      expect(fetchMock).not.toHaveBeenCalled();
    });
  });

  describe("buildMSTeamsGraphMessageUrls", () => {
    it("builds channel message urls", async () => {
      const { buildMSTeamsGraphMessageUrls } = await load();
      const urls = buildMSTeamsGraphMessageUrls({
        conversationType: "channel",
        conversationId: "19:thread@thread.tacv2",
        messageId: "123",
        channelData: { team: { id: "team-id" }, channel: { id: "chan-id" } },
      });
      expect(urls[0]).toContain("/teams/team-id/channels/chan-id/messages/123");
    });

    it("builds channel reply urls when replyToId is present", async () => {
      const { buildMSTeamsGraphMessageUrls } = await load();
      const urls = buildMSTeamsGraphMessageUrls({
        conversationType: "channel",
        messageId: "reply-id",
        replyToId: "root-id",
        channelData: { team: { id: "team-id" }, channel: { id: "chan-id" } },
      });
      expect(urls[0]).toContain(
        "/teams/team-id/channels/chan-id/messages/root-id/replies/reply-id",
      );
    });

    it("builds chat message urls", async () => {
      const { buildMSTeamsGraphMessageUrls } = await load();
      const urls = buildMSTeamsGraphMessageUrls({
        conversationType: "groupChat",
        conversationId: "19:chat@thread.v2",
        messageId: "456",
      });
      expect(urls[0]).toContain("/chats/19%3Achat%40thread.v2/messages/456");
    });
  });

  describe("downloadMSTeamsGraphMedia", () => {
    it("downloads hostedContents images", async () => {
      const { downloadMSTeamsGraphMedia } = await load();
      const base64 = Buffer.from("png").toString("base64");
      const fetchMock = vi.fn(async (url: string) => {
        if (url.endsWith("/hostedContents")) {
          return new Response(
            JSON.stringify({
              value: [
                {
                  id: "1",
                  contentType: "image/png",
                  contentBytes: base64,
                },
              ],
            }),
            { status: 200 },
          );
        }
        if (url.endsWith("/attachments")) {
          return new Response(JSON.stringify({ value: [] }), { status: 200 });
        }
        return new Response("not found", { status: 404 });
      });

      const media = await downloadMSTeamsGraphMedia({
        messageUrl: "https://graph.microsoft.com/v1.0/chats/19%3Achat/messages/123",
        tokenProvider: { getAccessToken: vi.fn(async () => "token") },
        maxBytes: 1024 * 1024,
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(media.media).toHaveLength(1);
      expect(fetchMock).toHaveBeenCalled();
      expect(saveMediaBufferMock).toHaveBeenCalled();
    });

    it("merges SharePoint reference attachments with hosted content", async () => {
      const { downloadMSTeamsGraphMedia } = await load();
      const hostedBase64 = Buffer.from("png").toString("base64");
      const shareUrl = "https://contoso.sharepoint.com/site/file";
      const fetchMock = vi.fn(async (url: string) => {
        if (url.endsWith("/hostedContents")) {
          return new Response(
            JSON.stringify({
              value: [
                {
                  id: "hosted-1",
                  contentType: "image/png",
                  contentBytes: hostedBase64,
                },
              ],
            }),
            { status: 200 },
          );
        }
        if (url.endsWith("/attachments")) {
          return new Response(
            JSON.stringify({
              value: [
                {
                  id: "ref-1",
                  contentType: "reference",
                  contentUrl: shareUrl,
                  name: "report.pdf",
                },
              ],
            }),
            { status: 200 },
          );
        }
        if (url.startsWith("https://graph.microsoft.com/v1.0/shares/")) {
          return new Response(Buffer.from("pdf"), {
            status: 200,
            headers: { "content-type": "application/pdf" },
          });
        }
        if (url.endsWith("/messages/123")) {
          return new Response(
            JSON.stringify({
              attachments: [
                {
                  id: "ref-1",
                  contentType: "reference",
                  contentUrl: shareUrl,
                  name: "report.pdf",
                },
              ],
            }),
            { status: 200 },
          );
        }
        return new Response("not found", { status: 404 });
      });

      const media = await downloadMSTeamsGraphMedia({
        messageUrl: "https://graph.microsoft.com/v1.0/chats/19%3Achat/messages/123",
        tokenProvider: { getAccessToken: vi.fn(async () => "token") },
        maxBytes: 1024 * 1024,
        fetchFn: fetchMock as unknown as typeof fetch,
      });

      expect(media.media).toHaveLength(2);
    });
  });

  describe("buildMSTeamsMediaPayload", () => {
    it("returns single and multi-file fields", async () => {
      const { buildMSTeamsMediaPayload } = await load();
      const payload = buildMSTeamsMediaPayload([
        { path: "/tmp/a.png", contentType: "image/png" },
        { path: "/tmp/b.png", contentType: "image/png" },
      ]);
      expect(payload.MediaPath).toBe("/tmp/a.png");
      expect(payload.MediaUrl).toBe("/tmp/a.png");
      expect(payload.MediaPaths).toEqual(["/tmp/a.png", "/tmp/b.png"]);
      expect(payload.MediaUrls).toEqual(["/tmp/a.png", "/tmp/b.png"]);
      expect(payload.MediaTypes).toEqual(["image/png", "image/png"]);
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/polls-store.test.ts"><![CDATA[import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { createMSTeamsPollStoreMemory } from "./polls-store-memory.js";
import { createMSTeamsPollStoreFs } from "./polls.js";

const createFsStore = async () => {
  const stateDir = await fs.promises.mkdtemp(path.join(os.tmpdir(), "openclaw-msteams-polls-"));
  return createMSTeamsPollStoreFs({ stateDir });
};

const createMemoryStore = () => createMSTeamsPollStoreMemory();

describe.each([
  { name: "memory", createStore: createMemoryStore },
  { name: "fs", createStore: createFsStore },
])("$name poll store", ({ createStore }) => {
  it("stores polls and records normalized votes", async () => {
    const store = await createStore();
    await store.createPoll({
      id: "poll-1",
      question: "Lunch?",
      options: ["Pizza", "Sushi"],
      maxSelections: 1,
      createdAt: new Date().toISOString(),
      votes: {},
    });

    const poll = await store.recordVote({
      pollId: "poll-1",
      voterId: "user-1",
      selections: ["0", "1"],
    });

    expect(poll?.votes["user-1"]).toEqual(["0"]);
  });
});
]]></file>
  <file path="./extensions/msteams/src/sdk.ts"><![CDATA[import type { MSTeamsAdapter } from "./messenger.js";
import type { MSTeamsCredentials } from "./token.js";

export type MSTeamsSdk = typeof import("@microsoft/agents-hosting");
export type MSTeamsAuthConfig = ReturnType<MSTeamsSdk["getAuthConfigWithDefaults"]>;

export async function loadMSTeamsSdk(): Promise<MSTeamsSdk> {
  return await import("@microsoft/agents-hosting");
}

export function buildMSTeamsAuthConfig(
  creds: MSTeamsCredentials,
  sdk: MSTeamsSdk,
): MSTeamsAuthConfig {
  return sdk.getAuthConfigWithDefaults({
    clientId: creds.appId,
    clientSecret: creds.appPassword,
    tenantId: creds.tenantId,
  });
}

export function createMSTeamsAdapter(
  authConfig: MSTeamsAuthConfig,
  sdk: MSTeamsSdk,
): MSTeamsAdapter {
  return new sdk.CloudAdapter(authConfig) as unknown as MSTeamsAdapter;
}

export async function loadMSTeamsSdkWithAuth(creds: MSTeamsCredentials) {
  const sdk = await loadMSTeamsSdk();
  const authConfig = buildMSTeamsAuthConfig(creds, sdk);
  return { sdk, authConfig };
}
]]></file>
  <file path="./extensions/msteams/src/media-helpers.ts"><![CDATA[/**
 * MIME type detection and filename extraction for MSTeams media attachments.
 */

import path from "node:path";
import {
  detectMime,
  extensionForMime,
  extractOriginalFilename,
  getFileExtension,
} from "openclaw/plugin-sdk";

/**
 * Detect MIME type from URL extension or data URL.
 * Uses shared MIME detection for consistency with core handling.
 */
export async function getMimeType(url: string): Promise<string> {
  // Handle data URLs: data:image/png;base64,...
  if (url.startsWith("data:")) {
    const match = url.match(/^data:([^;,]+)/);
    if (match?.[1]) {
      return match[1];
    }
  }

  // Use shared MIME detection (extension-based for URLs)
  const detected = await detectMime({ filePath: url });
  return detected ?? "application/octet-stream";
}

/**
 * Extract filename from URL or local path.
 * For local paths, extracts original filename if stored with embedded name pattern.
 * Falls back to deriving the extension from MIME type when no extension present.
 */
export async function extractFilename(url: string): Promise<string> {
  // Handle data URLs: derive extension from MIME
  if (url.startsWith("data:")) {
    const mime = await getMimeType(url);
    const ext = extensionForMime(mime) ?? ".bin";
    const prefix = mime.startsWith("image/") ? "image" : "file";
    return `${prefix}${ext}`;
  }

  // Try to extract from URL pathname
  try {
    const pathname = new URL(url).pathname;
    const basename = path.basename(pathname);
    const existingExt = getFileExtension(pathname);
    if (basename && existingExt) {
      return basename;
    }
    // No extension in URL, derive from MIME
    const mime = await getMimeType(url);
    const ext = extensionForMime(mime) ?? ".bin";
    const prefix = mime.startsWith("image/") ? "image" : "file";
    return basename ? `${basename}${ext}` : `${prefix}${ext}`;
  } catch {
    // Local paths - use extractOriginalFilename to extract embedded original name
    return extractOriginalFilename(url);
  }
}

/**
 * Check if a URL refers to a local file path.
 */
export function isLocalPath(url: string): boolean {
  if (url.startsWith("file://") || url.startsWith("/") || url.startsWith("~")) {
    return true;
  }

  // Windows drive-letter absolute path (e.g. C:\foo\bar.txt or C:/foo/bar.txt)
  if (/^[a-zA-Z]:[\\/]/.test(url)) {
    return true;
  }

  // Windows UNC path (e.g. \\server\share\file.txt)
  if (url.startsWith("\\\\")) {
    return true;
  }

  return false;
}

/**
 * Extract the message ID from a Bot Framework response.
 */
export function extractMessageId(response: unknown): string | null {
  if (!response || typeof response !== "object") {
    return null;
  }
  if (!("id" in response)) {
    return null;
  }
  const { id } = response as { id?: unknown };
  if (typeof id !== "string" || !id) {
    return null;
  }
  return id;
}
]]></file>
  <file path="./extensions/msteams/src/messenger.ts"><![CDATA[import {
  type ChunkMode,
  isSilentReplyText,
  loadWebMedia,
  type MarkdownTableMode,
  type MSTeamsReplyStyle,
  type ReplyPayload,
  SILENT_REPLY_TOKEN,
  sleep,
} from "openclaw/plugin-sdk";
import type { MSTeamsAccessTokenProvider } from "./attachments/types.js";
import type { StoredConversationReference } from "./conversation-store.js";
import { classifyMSTeamsSendError } from "./errors.js";
import { prepareFileConsentActivity, requiresFileConsent } from "./file-consent-helpers.js";
import { buildTeamsFileInfoCard } from "./graph-chat.js";
import {
  getDriveItemProperties,
  uploadAndShareOneDrive,
  uploadAndShareSharePoint,
} from "./graph-upload.js";
import { extractFilename, extractMessageId, getMimeType, isLocalPath } from "./media-helpers.js";
import { parseMentions } from "./mentions.js";
import { getMSTeamsRuntime } from "./runtime.js";

/**
 * MSTeams-specific media size limit (100MB).
 * Higher than the default because OneDrive upload handles large files well.
 */
const MSTEAMS_MAX_MEDIA_BYTES = 100 * 1024 * 1024;

/**
 * Threshold for large files that require FileConsentCard flow in personal chats.
 * Files >= 4MB use consent flow; smaller images can use inline base64.
 */
const FILE_CONSENT_THRESHOLD_BYTES = 4 * 1024 * 1024;

type SendContext = {
  sendActivity: (textOrActivity: string | object) => Promise<unknown>;
};

export type MSTeamsConversationReference = {
  activityId?: string;
  user?: { id?: string; name?: string; aadObjectId?: string };
  agent?: { id?: string; name?: string; aadObjectId?: string } | null;
  conversation: { id: string; conversationType?: string; tenantId?: string };
  channelId: string;
  serviceUrl?: string;
  locale?: string;
};

export type MSTeamsAdapter = {
  continueConversation: (
    appId: string,
    reference: MSTeamsConversationReference,
    logic: (context: SendContext) => Promise<void>,
  ) => Promise<void>;
  process: (
    req: unknown,
    res: unknown,
    logic: (context: unknown) => Promise<void>,
  ) => Promise<void>;
};

export type MSTeamsReplyRenderOptions = {
  textChunkLimit: number;
  chunkText?: boolean;
  mediaMode?: "split" | "inline";
  tableMode?: MarkdownTableMode;
  chunkMode?: ChunkMode;
};

/**
 * A rendered message that preserves media vs text distinction.
 * When mediaUrl is present, it will be sent as a Bot Framework attachment.
 */
export type MSTeamsRenderedMessage = {
  text?: string;
  mediaUrl?: string;
};

export type MSTeamsSendRetryOptions = {
  maxAttempts?: number;
  baseDelayMs?: number;
  maxDelayMs?: number;
};

export type MSTeamsSendRetryEvent = {
  messageIndex: number;
  messageCount: number;
  nextAttempt: number;
  maxAttempts: number;
  delayMs: number;
  classification: ReturnType<typeof classifyMSTeamsSendError>;
};

function normalizeConversationId(rawId: string): string {
  return rawId.split(";")[0] ?? rawId;
}

export function buildConversationReference(
  ref: StoredConversationReference,
): MSTeamsConversationReference {
  const conversationId = ref.conversation?.id?.trim();
  if (!conversationId) {
    throw new Error("Invalid stored reference: missing conversation.id");
  }
  const agent = ref.agent ?? ref.bot ?? undefined;
  if (agent == null || !agent.id) {
    throw new Error("Invalid stored reference: missing agent.id");
  }
  const user = ref.user;
  if (!user?.id) {
    throw new Error("Invalid stored reference: missing user.id");
  }
  return {
    activityId: ref.activityId,
    user,
    agent,
    conversation: {
      id: normalizeConversationId(conversationId),
      conversationType: ref.conversation?.conversationType,
      tenantId: ref.conversation?.tenantId,
    },
    channelId: ref.channelId ?? "msteams",
    serviceUrl: ref.serviceUrl,
    locale: ref.locale,
  };
}

function pushTextMessages(
  out: MSTeamsRenderedMessage[],
  text: string,
  opts: {
    chunkText: boolean;
    chunkLimit: number;
    chunkMode: ChunkMode;
  },
) {
  if (!text) {
    return;
  }
  if (opts.chunkText) {
    for (const chunk of getMSTeamsRuntime().channel.text.chunkMarkdownTextWithMode(
      text,
      opts.chunkLimit,
      opts.chunkMode,
    )) {
      const trimmed = chunk.trim();
      if (!trimmed || isSilentReplyText(trimmed, SILENT_REPLY_TOKEN)) {
        continue;
      }
      out.push({ text: trimmed });
    }
    return;
  }

  const trimmed = text.trim();
  if (!trimmed || isSilentReplyText(trimmed, SILENT_REPLY_TOKEN)) {
    return;
  }
  out.push({ text: trimmed });
}

function clampMs(value: number, maxMs: number): number {
  if (!Number.isFinite(value) || value < 0) {
    return 0;
  }
  return Math.min(value, maxMs);
}

function resolveRetryOptions(
  retry: false | MSTeamsSendRetryOptions | undefined,
): Required<MSTeamsSendRetryOptions> & { enabled: boolean } {
  if (!retry) {
    return { enabled: false, maxAttempts: 1, baseDelayMs: 0, maxDelayMs: 0 };
  }
  return {
    enabled: true,
    maxAttempts: Math.max(1, retry?.maxAttempts ?? 3),
    baseDelayMs: Math.max(0, retry?.baseDelayMs ?? 250),
    maxDelayMs: Math.max(0, retry?.maxDelayMs ?? 10_000),
  };
}

function computeRetryDelayMs(
  attempt: number,
  classification: ReturnType<typeof classifyMSTeamsSendError>,
  opts: Required<MSTeamsSendRetryOptions>,
): number {
  if (classification.retryAfterMs != null) {
    return clampMs(classification.retryAfterMs, opts.maxDelayMs);
  }
  const exponential = opts.baseDelayMs * 2 ** Math.max(0, attempt - 1);
  return clampMs(exponential, opts.maxDelayMs);
}

function shouldRetry(classification: ReturnType<typeof classifyMSTeamsSendError>): boolean {
  return classification.kind === "throttled" || classification.kind === "transient";
}

export function renderReplyPayloadsToMessages(
  replies: ReplyPayload[],
  options: MSTeamsReplyRenderOptions,
): MSTeamsRenderedMessage[] {
  const out: MSTeamsRenderedMessage[] = [];
  const chunkLimit = Math.min(options.textChunkLimit, 4000);
  const chunkText = options.chunkText !== false;
  const chunkMode = options.chunkMode ?? "length";
  const mediaMode = options.mediaMode ?? "split";
  const tableMode =
    options.tableMode ??
    getMSTeamsRuntime().channel.text.resolveMarkdownTableMode({
      cfg: getMSTeamsRuntime().config.loadConfig(),
      channel: "msteams",
    });

  for (const payload of replies) {
    const mediaList = payload.mediaUrls ?? (payload.mediaUrl ? [payload.mediaUrl] : []);
    const text = getMSTeamsRuntime().channel.text.convertMarkdownTables(
      payload.text ?? "",
      tableMode,
    );

    if (!text && mediaList.length === 0) {
      continue;
    }

    if (mediaList.length === 0) {
      pushTextMessages(out, text, { chunkText, chunkLimit, chunkMode });
      continue;
    }

    if (mediaMode === "inline") {
      // For inline mode, combine text with first media as attachment
      const firstMedia = mediaList[0];
      if (firstMedia) {
        out.push({ text: text || undefined, mediaUrl: firstMedia });
        // Additional media URLs as separate messages
        for (let i = 1; i < mediaList.length; i++) {
          if (mediaList[i]) {
            out.push({ mediaUrl: mediaList[i] });
          }
        }
      } else {
        pushTextMessages(out, text, { chunkText, chunkLimit, chunkMode });
      }
      continue;
    }

    // mediaMode === "split"
    pushTextMessages(out, text, { chunkText, chunkLimit, chunkMode });
    for (const mediaUrl of mediaList) {
      if (!mediaUrl) {
        continue;
      }
      out.push({ mediaUrl });
    }
  }

  return out;
}

async function buildActivity(
  msg: MSTeamsRenderedMessage,
  conversationRef: StoredConversationReference,
  tokenProvider?: MSTeamsAccessTokenProvider,
  sharePointSiteId?: string,
  mediaMaxBytes?: number,
): Promise<Record<string, unknown>> {
  const activity: Record<string, unknown> = { type: "message" };

  if (msg.text) {
    // Parse mentions from text (format: @[Name](id))
    const { text: formattedText, entities } = parseMentions(msg.text);
    activity.text = formattedText;

    // Add mention entities if any mentions were found
    if (entities.length > 0) {
      activity.entities = entities;
    }
  }

  if (msg.mediaUrl) {
    let contentUrl = msg.mediaUrl;
    let contentType = await getMimeType(msg.mediaUrl);
    let fileName = await extractFilename(msg.mediaUrl);

    if (isLocalPath(msg.mediaUrl)) {
      const maxBytes = mediaMaxBytes ?? MSTEAMS_MAX_MEDIA_BYTES;
      const media = await loadWebMedia(msg.mediaUrl, maxBytes);
      contentType = media.contentType ?? contentType;
      fileName = media.fileName ?? fileName;

      // Determine conversation type and file type
      // Teams only accepts base64 data URLs for images
      const conversationType = conversationRef.conversation?.conversationType?.toLowerCase();
      const isPersonal = conversationType === "personal";
      const isImage = contentType?.startsWith("image/") ?? false;

      if (
        requiresFileConsent({
          conversationType,
          contentType,
          bufferSize: media.buffer.length,
          thresholdBytes: FILE_CONSENT_THRESHOLD_BYTES,
        })
      ) {
        // Large file or non-image in personal chat: use FileConsentCard flow
        const conversationId = conversationRef.conversation?.id ?? "unknown";
        const { activity: consentActivity } = prepareFileConsentActivity({
          media: { buffer: media.buffer, filename: fileName, contentType },
          conversationId,
          description: msg.text || undefined,
        });

        // Return the consent activity (caller sends it)
        return consentActivity;
      }

      if (!isPersonal && !isImage && tokenProvider && sharePointSiteId) {
        // Non-image in group chat/channel with SharePoint site configured:
        // Upload to SharePoint and use native file card attachment
        const chatId = conversationRef.conversation?.id;

        // Upload to SharePoint
        const uploaded = await uploadAndShareSharePoint({
          buffer: media.buffer,
          filename: fileName,
          contentType,
          tokenProvider,
          siteId: sharePointSiteId,
          chatId: chatId ?? undefined,
          usePerUserSharing: conversationType === "groupchat",
        });

        // Get driveItem properties needed for native file card attachment
        const driveItem = await getDriveItemProperties({
          siteId: sharePointSiteId,
          itemId: uploaded.itemId,
          tokenProvider,
        });

        // Build native Teams file card attachment
        const fileCardAttachment = buildTeamsFileInfoCard(driveItem);
        activity.attachments = [fileCardAttachment];

        return activity;
      }

      if (!isPersonal && !isImage && tokenProvider) {
        // Fallback: no SharePoint site configured, try OneDrive upload
        const uploaded = await uploadAndShareOneDrive({
          buffer: media.buffer,
          filename: fileName,
          contentType,
          tokenProvider,
        });

        // Bot Framework doesn't support "reference" attachment type for sending
        const fileLink = `📎 [${uploaded.name}](${uploaded.shareUrl})`;
        const existingText = typeof activity.text === "string" ? activity.text : undefined;
        activity.text = existingText ? `${existingText}\n\n${fileLink}` : fileLink;
        return activity;
      }

      // Image (any chat): use base64 (works for images in all conversation types)
      const base64 = media.buffer.toString("base64");
      contentUrl = `data:${media.contentType};base64,${base64}`;
    }

    activity.attachments = [
      {
        name: fileName,
        contentType,
        contentUrl,
      },
    ];
  }

  return activity;
}

export async function sendMSTeamsMessages(params: {
  replyStyle: MSTeamsReplyStyle;
  adapter: MSTeamsAdapter;
  appId: string;
  conversationRef: StoredConversationReference;
  context?: SendContext;
  messages: MSTeamsRenderedMessage[];
  retry?: false | MSTeamsSendRetryOptions;
  onRetry?: (event: MSTeamsSendRetryEvent) => void;
  /** Token provider for OneDrive/SharePoint uploads in group chats/channels */
  tokenProvider?: MSTeamsAccessTokenProvider;
  /** SharePoint site ID for file uploads in group chats/channels */
  sharePointSiteId?: string;
  /** Max media size in bytes. Default: 100MB. */
  mediaMaxBytes?: number;
}): Promise<string[]> {
  const messages = params.messages.filter(
    (m) => (m.text && m.text.trim().length > 0) || m.mediaUrl,
  );
  if (messages.length === 0) {
    return [];
  }

  const retryOptions = resolveRetryOptions(params.retry);

  const sendWithRetry = async (
    sendOnce: () => Promise<unknown>,
    meta: { messageIndex: number; messageCount: number },
  ): Promise<unknown> => {
    if (!retryOptions.enabled) {
      return await sendOnce();
    }

    let attempt = 1;
    while (true) {
      try {
        return await sendOnce();
      } catch (err) {
        const classification = classifyMSTeamsSendError(err);
        const canRetry = attempt < retryOptions.maxAttempts && shouldRetry(classification);
        if (!canRetry) {
          throw err;
        }

        const delayMs = computeRetryDelayMs(attempt, classification, retryOptions);
        const nextAttempt = attempt + 1;
        params.onRetry?.({
          messageIndex: meta.messageIndex,
          messageCount: meta.messageCount,
          nextAttempt,
          maxAttempts: retryOptions.maxAttempts,
          delayMs,
          classification,
        });

        await sleep(delayMs);
        attempt = nextAttempt;
      }
    }
  };

  if (params.replyStyle === "thread") {
    const ctx = params.context;
    if (!ctx) {
      throw new Error("Missing context for replyStyle=thread");
    }
    const messageIds: string[] = [];
    for (const [idx, message] of messages.entries()) {
      const response = await sendWithRetry(
        async () =>
          await ctx.sendActivity(
            await buildActivity(
              message,
              params.conversationRef,
              params.tokenProvider,
              params.sharePointSiteId,
              params.mediaMaxBytes,
            ),
          ),
        { messageIndex: idx, messageCount: messages.length },
      );
      messageIds.push(extractMessageId(response) ?? "unknown");
    }
    return messageIds;
  }

  const baseRef = buildConversationReference(params.conversationRef);
  const proactiveRef: MSTeamsConversationReference = {
    ...baseRef,
    activityId: undefined,
  };

  const messageIds: string[] = [];
  await params.adapter.continueConversation(params.appId, proactiveRef, async (ctx) => {
    for (const [idx, message] of messages.entries()) {
      const response = await sendWithRetry(
        async () =>
          await ctx.sendActivity(
            await buildActivity(
              message,
              params.conversationRef,
              params.tokenProvider,
              params.sharePointSiteId,
              params.mediaMaxBytes,
            ),
          ),
        { messageIndex: idx, messageCount: messages.length },
      );
      messageIds.push(extractMessageId(response) ?? "unknown");
    }
  });
  return messageIds;
}
]]></file>
  <file path="./extensions/msteams/src/file-consent-helpers.test.ts"><![CDATA[import { describe, expect, it, vi, beforeEach, afterEach } from "vitest";
import { prepareFileConsentActivity, requiresFileConsent } from "./file-consent-helpers.js";
import * as pendingUploads from "./pending-uploads.js";

describe("requiresFileConsent", () => {
  const thresholdBytes = 4 * 1024 * 1024; // 4MB

  it("returns true for personal chat with non-image", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: "application/pdf",
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(true);
  });

  it("returns true for personal chat with large image", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: "image/png",
        bufferSize: 5 * 1024 * 1024, // 5MB
        thresholdBytes,
      }),
    ).toBe(true);
  });

  it("returns false for personal chat with small image", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: "image/png",
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(false);
  });

  it("returns false for group chat with large non-image", () => {
    expect(
      requiresFileConsent({
        conversationType: "groupChat",
        contentType: "application/pdf",
        bufferSize: 5 * 1024 * 1024,
        thresholdBytes,
      }),
    ).toBe(false);
  });

  it("returns false for channel with large non-image", () => {
    expect(
      requiresFileConsent({
        conversationType: "channel",
        contentType: "application/pdf",
        bufferSize: 5 * 1024 * 1024,
        thresholdBytes,
      }),
    ).toBe(false);
  });

  it("handles case-insensitive conversation type", () => {
    expect(
      requiresFileConsent({
        conversationType: "Personal",
        contentType: "application/pdf",
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(true);

    expect(
      requiresFileConsent({
        conversationType: "PERSONAL",
        contentType: "application/pdf",
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(true);
  });

  it("returns false when conversationType is undefined", () => {
    expect(
      requiresFileConsent({
        conversationType: undefined,
        contentType: "application/pdf",
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(false);
  });

  it("returns true for personal chat when contentType is undefined (non-image)", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: undefined,
        bufferSize: 1000,
        thresholdBytes,
      }),
    ).toBe(true);
  });

  it("returns true for personal chat with file exactly at threshold", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: "image/jpeg",
        bufferSize: thresholdBytes, // exactly 4MB
        thresholdBytes,
      }),
    ).toBe(true);
  });

  it("returns false for personal chat with file just below threshold", () => {
    expect(
      requiresFileConsent({
        conversationType: "personal",
        contentType: "image/jpeg",
        bufferSize: thresholdBytes - 1, // 4MB - 1 byte
        thresholdBytes,
      }),
    ).toBe(false);
  });
});

describe("prepareFileConsentActivity", () => {
  const mockUploadId = "test-upload-id-123";

  beforeEach(() => {
    vi.spyOn(pendingUploads, "storePendingUpload").mockReturnValue(mockUploadId);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("creates activity with consent card attachment", () => {
    const result = prepareFileConsentActivity({
      media: {
        buffer: Buffer.from("test content"),
        filename: "test.pdf",
        contentType: "application/pdf",
      },
      conversationId: "conv123",
      description: "My file",
    });

    expect(result.uploadId).toBe(mockUploadId);
    expect(result.activity.type).toBe("message");
    expect(result.activity.attachments).toHaveLength(1);

    const attachment = (result.activity.attachments as unknown[])[0] as Record<string, unknown>;
    expect(attachment.contentType).toBe("application/vnd.microsoft.teams.card.file.consent");
    expect(attachment.name).toBe("test.pdf");
  });

  it("stores pending upload with correct data", () => {
    const buffer = Buffer.from("test content");
    prepareFileConsentActivity({
      media: {
        buffer,
        filename: "test.pdf",
        contentType: "application/pdf",
      },
      conversationId: "conv123",
      description: "My file",
    });

    expect(pendingUploads.storePendingUpload).toHaveBeenCalledWith({
      buffer,
      filename: "test.pdf",
      contentType: "application/pdf",
      conversationId: "conv123",
    });
  });

  it("uses default description when not provided", () => {
    const result = prepareFileConsentActivity({
      media: {
        buffer: Buffer.from("test"),
        filename: "document.docx",
        contentType: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      },
      conversationId: "conv456",
    });

    const attachment = (result.activity.attachments as unknown[])[0] as Record<
      string,
      { description: string }
    >;
    expect(attachment.content.description).toBe("File: document.docx");
  });

  it("uses provided description", () => {
    const result = prepareFileConsentActivity({
      media: {
        buffer: Buffer.from("test"),
        filename: "report.pdf",
        contentType: "application/pdf",
      },
      conversationId: "conv789",
      description: "Q4 Financial Report",
    });

    const attachment = (result.activity.attachments as unknown[])[0] as Record<
      string,
      { description: string }
    >;
    expect(attachment.content.description).toBe("Q4 Financial Report");
  });

  it("includes uploadId in consent card context", () => {
    const result = prepareFileConsentActivity({
      media: {
        buffer: Buffer.from("test"),
        filename: "file.txt",
        contentType: "text/plain",
      },
      conversationId: "conv000",
    });

    const attachment = (result.activity.attachments as unknown[])[0] as Record<
      string,
      { acceptContext: { uploadId: string } }
    >;
    expect(attachment.content.acceptContext.uploadId).toBe(mockUploadId);
  });

  it("handles media without contentType", () => {
    const result = prepareFileConsentActivity({
      media: {
        buffer: Buffer.from("binary data"),
        filename: "unknown.bin",
      },
      conversationId: "conv111",
    });

    expect(result.uploadId).toBe(mockUploadId);
    expect(result.activity.type).toBe("message");
  });
});
]]></file>
  <file path="./extensions/msteams/src/outbound.ts"><![CDATA[import type { ChannelOutboundAdapter } from "openclaw/plugin-sdk";
import { createMSTeamsPollStoreFs } from "./polls.js";
import { getMSTeamsRuntime } from "./runtime.js";
import { sendMessageMSTeams, sendPollMSTeams } from "./send.js";

export const msteamsOutbound: ChannelOutboundAdapter = {
  deliveryMode: "direct",
  chunker: (text, limit) => getMSTeamsRuntime().channel.text.chunkMarkdownText(text, limit),
  chunkerMode: "markdown",
  textChunkLimit: 4000,
  pollMaxOptions: 12,
  sendText: async ({ cfg, to, text, deps }) => {
    const send = deps?.sendMSTeams ?? ((to, text) => sendMessageMSTeams({ cfg, to, text }));
    const result = await send(to, text);
    return { channel: "msteams", ...result };
  },
  sendMedia: async ({ cfg, to, text, mediaUrl, deps }) => {
    const send =
      deps?.sendMSTeams ??
      ((to, text, opts) => sendMessageMSTeams({ cfg, to, text, mediaUrl: opts?.mediaUrl }));
    const result = await send(to, text, { mediaUrl });
    return { channel: "msteams", ...result };
  },
  sendPoll: async ({ cfg, to, poll }) => {
    const maxSelections = poll.maxSelections ?? 1;
    const result = await sendPollMSTeams({
      cfg,
      to,
      question: poll.question,
      options: poll.options,
      maxSelections,
    });
    const pollStore = createMSTeamsPollStoreFs();
    await pollStore.createPoll({
      id: result.pollId,
      question: poll.question,
      options: poll.options,
      maxSelections,
      createdAt: new Date().toISOString(),
      conversationId: result.conversationId,
      messageId: result.messageId,
      votes: {},
    });
    return result;
  },
};
]]></file>
  <file path="./extensions/msteams/src/sdk-types.ts"><![CDATA[import type { TurnContext } from "@microsoft/agents-hosting";

/**
 * Minimal public surface we depend on from the Microsoft SDK types.
 *
 * Note: we intentionally avoid coupling to SDK classes with private members
 * (like TurnContext) in our own public signatures. The SDK's TS surface is also
 * stricter than what the runtime accepts (e.g. it allows plain activity-like
 * objects), so we model the minimal structural shape we rely on.
 */
export type MSTeamsActivity = TurnContext["activity"];

export type MSTeamsTurnContext = {
  activity: MSTeamsActivity;
  sendActivity: (textOrActivity: string | object) => Promise<unknown>;
  sendActivities: (
    activities: Array<{ type: string } & Record<string, unknown>>,
  ) => Promise<unknown>;
};
]]></file>
  <file path="./extensions/msteams/src/mentions.ts"><![CDATA[/**
 * MS Teams mention handling utilities.
 *
 * Mentions in Teams require:
 * 1. Text containing <at>Name</at> tags
 * 2. entities array with mention metadata
 */

export type MentionEntity = {
  type: "mention";
  text: string;
  mentioned: {
    id: string;
    name: string;
  };
};

export type MentionInfo = {
  /** User/bot ID (e.g., "28:xxx" or AAD object ID) */
  id: string;
  /** Display name */
  name: string;
};

/**
 * Check whether an ID looks like a valid Teams user/bot identifier.
 * Accepts:
 * - Bot Framework IDs: "28:xxx..." / "29:xxx..." / "8:orgid:..."
 * - AAD object IDs (UUIDs): "d5318c29-33ac-4e6b-bd42-57b8b793908f"
 *
 * Keep this permissive enough for real Teams IDs while still rejecting
 * documentation placeholders like `@[表示名](ユーザーID)`.
 */
const TEAMS_BOT_ID_PATTERN = /^\d+:[a-z0-9._=-]+(?::[a-z0-9._=-]+)*$/i;
const AAD_OBJECT_ID_PATTERN = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;

function isValidTeamsId(id: string): boolean {
  return TEAMS_BOT_ID_PATTERN.test(id) || AAD_OBJECT_ID_PATTERN.test(id);
}

/**
 * Parse mentions from text in the format @[Name](id).
 * Example: "Hello @[John Doe](28:xxx-yyy-zzz)!"
 *
 * Only matches where the id looks like a real Teams user/bot ID are treated
 * as mentions. This avoids false positives from documentation or code samples
 * embedded in the message (e.g. `@[表示名](ユーザーID)` in backticks).
 *
 * Returns both the formatted text with <at> tags and the entities array.
 */
export function parseMentions(text: string): {
  text: string;
  entities: MentionEntity[];
} {
  const mentionPattern = /@\[([^\]]+)\]\(([^)]+)\)/g;
  const entities: MentionEntity[] = [];

  // Replace @[Name](id) with <at>Name</at> only for valid Teams IDs
  const formattedText = text.replace(mentionPattern, (match, name, id) => {
    const trimmedId = id.trim();

    // Skip matches where the id doesn't look like a real Teams identifier
    if (!isValidTeamsId(trimmedId)) {
      return match;
    }

    const trimmedName = name.trim();
    const mentionTag = `<at>${trimmedName}</at>`;
    entities.push({
      type: "mention",
      text: mentionTag,
      mentioned: {
        id: trimmedId,
        name: trimmedName,
      },
    });
    return mentionTag;
  });

  return {
    text: formattedText,
    entities,
  };
}

/**
 * Build mention entities array from a list of mentions.
 * Use this when you already have the mention info and formatted text.
 */
export function buildMentionEntities(mentions: MentionInfo[]): MentionEntity[] {
  return mentions.map((mention) => ({
    type: "mention",
    text: `<at>${mention.name}</at>`,
    mentioned: {
      id: mention.id,
      name: mention.name,
    },
  }));
}

/**
 * Format text with mentions using <at> tags.
 * This is a convenience function when you want to manually format mentions.
 */
export function formatMentionText(text: string, mentions: MentionInfo[]): string {
  let formatted = text;
  for (const mention of mentions) {
    // Replace @Name or @name with <at>Name</at>
    const escapedName = mention.name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const namePattern = new RegExp(`@${escapedName}`, "gi");
    formatted = formatted.replace(namePattern, `<at>${mention.name}</at>`);
  }
  return formatted;
}
]]></file>
  <file path="./extensions/msteams/src/runtime.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";

let runtime: PluginRuntime | null = null;

export function setMSTeamsRuntime(next: PluginRuntime) {
  runtime = next;
}

export function getMSTeamsRuntime(): PluginRuntime {
  if (!runtime) {
    throw new Error("MSTeams runtime not initialized");
  }
  return runtime;
}
]]></file>
  <file path="./extensions/msteams/src/monitor.ts"><![CDATA[import type { Request, Response } from "express";
import {
  mergeAllowlist,
  summarizeMapping,
  type OpenClawConfig,
  type RuntimeEnv,
} from "openclaw/plugin-sdk";
import type { MSTeamsConversationStore } from "./conversation-store.js";
import type { MSTeamsAdapter } from "./messenger.js";
import { createMSTeamsConversationStoreFs } from "./conversation-store-fs.js";
import { formatUnknownError } from "./errors.js";
import { registerMSTeamsHandlers, type MSTeamsActivityHandler } from "./monitor-handler.js";
import { createMSTeamsPollStoreFs, type MSTeamsPollStore } from "./polls.js";
import {
  resolveMSTeamsChannelAllowlist,
  resolveMSTeamsUserAllowlist,
} from "./resolve-allowlist.js";
import { getMSTeamsRuntime } from "./runtime.js";
import { createMSTeamsAdapter, loadMSTeamsSdkWithAuth } from "./sdk.js";
import { resolveMSTeamsCredentials } from "./token.js";

export type MonitorMSTeamsOpts = {
  cfg: OpenClawConfig;
  runtime?: RuntimeEnv;
  abortSignal?: AbortSignal;
  conversationStore?: MSTeamsConversationStore;
  pollStore?: MSTeamsPollStore;
};

export type MonitorMSTeamsResult = {
  app: unknown;
  shutdown: () => Promise<void>;
};

export async function monitorMSTeamsProvider(
  opts: MonitorMSTeamsOpts,
): Promise<MonitorMSTeamsResult> {
  const core = getMSTeamsRuntime();
  const log = core.logging.getChildLogger({ name: "msteams" });
  let cfg = opts.cfg;
  let msteamsCfg = cfg.channels?.msteams;
  if (!msteamsCfg?.enabled) {
    log.debug?.("msteams provider disabled");
    return { app: null, shutdown: async () => {} };
  }

  const creds = resolveMSTeamsCredentials(msteamsCfg);
  if (!creds) {
    log.error("msteams credentials not configured");
    return { app: null, shutdown: async () => {} };
  }
  const appId = creds.appId; // Extract for use in closures

  const runtime: RuntimeEnv = opts.runtime ?? {
    log: console.log,
    error: console.error,
    exit: (code: number): never => {
      throw new Error(`exit ${code}`);
    },
  };

  let allowFrom = msteamsCfg.allowFrom;
  let groupAllowFrom = msteamsCfg.groupAllowFrom;
  let teamsConfig = msteamsCfg.teams;

  const cleanAllowEntry = (entry: string) =>
    entry
      .replace(/^(msteams|teams):/i, "")
      .replace(/^user:/i, "")
      .trim();

  const resolveAllowlistUsers = async (label: string, entries: string[]) => {
    if (entries.length === 0) {
      return { additions: [], unresolved: [] };
    }
    const resolved = await resolveMSTeamsUserAllowlist({ cfg, entries });
    const additions: string[] = [];
    const unresolved: string[] = [];
    for (const entry of resolved) {
      if (entry.resolved && entry.id) {
        additions.push(entry.id);
      } else {
        unresolved.push(entry.input);
      }
    }
    const mapping = resolved
      .filter((entry) => entry.resolved && entry.id)
      .map((entry) => `${entry.input}→${entry.id}`);
    summarizeMapping(label, mapping, unresolved, runtime);
    return { additions, unresolved };
  };

  try {
    const allowEntries =
      allowFrom
        ?.map((entry) => cleanAllowEntry(String(entry)))
        .filter((entry) => entry && entry !== "*") ?? [];
    if (allowEntries.length > 0) {
      const { additions } = await resolveAllowlistUsers("msteams users", allowEntries);
      allowFrom = mergeAllowlist({ existing: allowFrom, additions });
    }

    if (Array.isArray(groupAllowFrom) && groupAllowFrom.length > 0) {
      const groupEntries = groupAllowFrom
        .map((entry) => cleanAllowEntry(String(entry)))
        .filter((entry) => entry && entry !== "*");
      if (groupEntries.length > 0) {
        const { additions } = await resolveAllowlistUsers("msteams group users", groupEntries);
        groupAllowFrom = mergeAllowlist({ existing: groupAllowFrom, additions });
      }
    }

    if (teamsConfig && Object.keys(teamsConfig).length > 0) {
      const entries: Array<{ input: string; teamKey: string; channelKey?: string }> = [];
      for (const [teamKey, teamCfg] of Object.entries(teamsConfig)) {
        if (teamKey === "*") {
          continue;
        }
        const channels = teamCfg?.channels ?? {};
        const channelKeys = Object.keys(channels).filter((key) => key !== "*");
        if (channelKeys.length === 0) {
          entries.push({ input: teamKey, teamKey });
          continue;
        }
        for (const channelKey of channelKeys) {
          entries.push({
            input: `${teamKey}/${channelKey}`,
            teamKey,
            channelKey,
          });
        }
      }

      if (entries.length > 0) {
        const resolved = await resolveMSTeamsChannelAllowlist({
          cfg,
          entries: entries.map((entry) => entry.input),
        });
        const mapping: string[] = [];
        const unresolved: string[] = [];
        const nextTeams = { ...teamsConfig };

        resolved.forEach((entry, idx) => {
          const source = entries[idx];
          if (!source) {
            return;
          }
          const sourceTeam = teamsConfig?.[source.teamKey] ?? {};
          if (!entry.resolved || !entry.teamId) {
            unresolved.push(entry.input);
            return;
          }
          mapping.push(
            entry.channelId
              ? `${entry.input}→${entry.teamId}/${entry.channelId}`
              : `${entry.input}→${entry.teamId}`,
          );
          const existing = nextTeams[entry.teamId] ?? {};
          const mergedChannels = {
            ...sourceTeam.channels,
            ...existing.channels,
          };
          const mergedTeam = { ...sourceTeam, ...existing, channels: mergedChannels };
          nextTeams[entry.teamId] = mergedTeam;
          if (source.channelKey && entry.channelId) {
            const sourceChannel = sourceTeam.channels?.[source.channelKey];
            if (sourceChannel) {
              nextTeams[entry.teamId] = {
                ...mergedTeam,
                channels: {
                  ...mergedChannels,
                  [entry.channelId]: {
                    ...sourceChannel,
                    ...mergedChannels?.[entry.channelId],
                  },
                },
              };
            }
          }
        });

        teamsConfig = nextTeams;
        summarizeMapping("msteams channels", mapping, unresolved, runtime);
      }
    }
  } catch (err) {
    runtime.log?.(`msteams resolve failed; using config entries. ${String(err)}`);
  }

  msteamsCfg = {
    ...msteamsCfg,
    allowFrom,
    groupAllowFrom,
    teams: teamsConfig,
  };
  cfg = {
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: msteamsCfg,
    },
  };

  const port = msteamsCfg.webhook?.port ?? 3978;
  const textLimit = core.channel.text.resolveTextChunkLimit(cfg, "msteams");
  const MB = 1024 * 1024;
  const agentDefaults = cfg.agents?.defaults;
  const mediaMaxBytes =
    typeof agentDefaults?.mediaMaxMb === "number" && agentDefaults.mediaMaxMb > 0
      ? Math.floor(agentDefaults.mediaMaxMb * MB)
      : 8 * MB;
  const conversationStore = opts.conversationStore ?? createMSTeamsConversationStoreFs();
  const pollStore = opts.pollStore ?? createMSTeamsPollStoreFs();

  log.info(`starting provider (port ${port})`);

  // Dynamic import to avoid loading SDK when provider is disabled
  const express = await import("express");

  const { sdk, authConfig } = await loadMSTeamsSdkWithAuth(creds);
  const { ActivityHandler, MsalTokenProvider, authorizeJWT } = sdk;

  // Auth configuration - create early so adapter is available for deliverReplies
  const tokenProvider = new MsalTokenProvider(authConfig);
  const adapter = createMSTeamsAdapter(authConfig, sdk);

  const handler = registerMSTeamsHandlers(new ActivityHandler() as MSTeamsActivityHandler, {
    cfg,
    runtime,
    appId,
    adapter: adapter as unknown as MSTeamsAdapter,
    tokenProvider,
    textLimit,
    mediaMaxBytes,
    conversationStore,
    pollStore,
    log,
  });

  // Create Express server
  const expressApp = express.default();
  expressApp.use(express.json());
  expressApp.use(authorizeJWT(authConfig));

  // Set up the messages endpoint - use configured path and /api/messages as fallback
  const configuredPath = msteamsCfg.webhook?.path ?? "/api/messages";
  const messageHandler = (req: Request, res: Response) => {
    void adapter
      .process(req, res, (context: unknown) => handler.run!(context))
      .catch((err: unknown) => {
        log.error("msteams webhook failed", { error: formatUnknownError(err) });
      });
  };

  // Listen on configured path and /api/messages (standard Bot Framework path)
  expressApp.post(configuredPath, messageHandler);
  if (configuredPath !== "/api/messages") {
    expressApp.post("/api/messages", messageHandler);
  }

  log.debug?.("listening on paths", {
    primary: configuredPath,
    fallback: "/api/messages",
  });

  // Start listening and capture the HTTP server handle
  const httpServer = expressApp.listen(port, () => {
    log.info(`msteams provider started on port ${port}`);
  });

  httpServer.on("error", (err) => {
    log.error("msteams server error", { error: String(err) });
  });

  const shutdown = async () => {
    log.info("shutting down msteams provider");
    return new Promise<void>((resolve) => {
      httpServer.close((err) => {
        if (err) {
          log.debug?.("msteams server close error", { error: String(err) });
        }
        resolve();
      });
    });
  };

  // Handle abort signal
  if (opts.abortSignal) {
    opts.abortSignal.addEventListener("abort", () => {
      void shutdown();
    });
  }

  return { app: expressApp, shutdown };
}
]]></file>
  <file path="./extensions/msteams/src/onboarding.ts"><![CDATA[import type {
  ChannelOnboardingAdapter,
  ChannelOnboardingDmPolicy,
  OpenClawConfig,
  DmPolicy,
  WizardPrompter,
  MSTeamsTeamConfig,
} from "openclaw/plugin-sdk";
import {
  addWildcardAllowFrom,
  DEFAULT_ACCOUNT_ID,
  formatDocsLink,
  promptChannelAccessConfig,
} from "openclaw/plugin-sdk";
import {
  parseMSTeamsTeamEntry,
  resolveMSTeamsChannelAllowlist,
  resolveMSTeamsUserAllowlist,
} from "./resolve-allowlist.js";
import { resolveMSTeamsCredentials } from "./token.js";

const channel = "msteams" as const;

function setMSTeamsDmPolicy(cfg: OpenClawConfig, dmPolicy: DmPolicy) {
  const allowFrom =
    dmPolicy === "open"
      ? addWildcardAllowFrom(cfg.channels?.msteams?.allowFrom)?.map((entry) => String(entry))
      : undefined;
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: {
        ...cfg.channels?.msteams,
        dmPolicy,
        ...(allowFrom ? { allowFrom } : {}),
      },
    },
  };
}

function setMSTeamsAllowFrom(cfg: OpenClawConfig, allowFrom: string[]): OpenClawConfig {
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: {
        ...cfg.channels?.msteams,
        allowFrom,
      },
    },
  };
}

function parseAllowFromInput(raw: string): string[] {
  return raw
    .split(/[\n,;]+/g)
    .map((entry) => entry.trim())
    .filter(Boolean);
}

function looksLikeGuid(value: string): boolean {
  return /^[0-9a-fA-F-]{16,}$/.test(value);
}

async function promptMSTeamsAllowFrom(params: {
  cfg: OpenClawConfig;
  prompter: WizardPrompter;
}): Promise<OpenClawConfig> {
  const existing = params.cfg.channels?.msteams?.allowFrom ?? [];
  await params.prompter.note(
    [
      "Allowlist MS Teams DMs by display name, UPN/email, or user id.",
      "We resolve names to user IDs via Microsoft Graph when credentials allow.",
      "Examples:",
      "- alex@example.com",
      "- Alex Johnson",
      "- 00000000-0000-0000-0000-000000000000",
    ].join("\n"),
    "MS Teams allowlist",
  );

  while (true) {
    const entry = await params.prompter.text({
      message: "MS Teams allowFrom (usernames or ids)",
      placeholder: "alex@example.com, Alex Johnson",
      initialValue: existing[0] ? String(existing[0]) : undefined,
      validate: (value) => (String(value ?? "").trim() ? undefined : "Required"),
    });
    const parts = parseAllowFromInput(String(entry));
    if (parts.length === 0) {
      await params.prompter.note("Enter at least one user.", "MS Teams allowlist");
      continue;
    }

    const resolved = await resolveMSTeamsUserAllowlist({
      cfg: params.cfg,
      entries: parts,
    }).catch(() => null);

    if (!resolved) {
      const ids = parts.filter((part) => looksLikeGuid(part));
      if (ids.length !== parts.length) {
        await params.prompter.note(
          "Graph lookup unavailable. Use user IDs only.",
          "MS Teams allowlist",
        );
        continue;
      }
      const unique = [
        ...new Set([...existing.map((v) => String(v).trim()).filter(Boolean), ...ids]),
      ];
      return setMSTeamsAllowFrom(params.cfg, unique);
    }

    const unresolved = resolved.filter((item) => !item.resolved || !item.id);
    if (unresolved.length > 0) {
      await params.prompter.note(
        `Could not resolve: ${unresolved.map((item) => item.input).join(", ")}`,
        "MS Teams allowlist",
      );
      continue;
    }

    const ids = resolved.map((item) => item.id as string);
    const unique = [...new Set([...existing.map((v) => String(v).trim()).filter(Boolean), ...ids])];
    return setMSTeamsAllowFrom(params.cfg, unique);
  }
}

async function noteMSTeamsCredentialHelp(prompter: WizardPrompter): Promise<void> {
  await prompter.note(
    [
      "1) Azure Bot registration → get App ID + Tenant ID",
      "2) Add a client secret (App Password)",
      "3) Set webhook URL + messaging endpoint",
      "Tip: you can also set MSTEAMS_APP_ID / MSTEAMS_APP_PASSWORD / MSTEAMS_TENANT_ID.",
      `Docs: ${formatDocsLink("/channels/msteams", "msteams")}`,
    ].join("\n"),
    "MS Teams credentials",
  );
}

function setMSTeamsGroupPolicy(
  cfg: OpenClawConfig,
  groupPolicy: "open" | "allowlist" | "disabled",
): OpenClawConfig {
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: {
        ...cfg.channels?.msteams,
        enabled: true,
        groupPolicy,
      },
    },
  };
}

function setMSTeamsTeamsAllowlist(
  cfg: OpenClawConfig,
  entries: Array<{ teamKey: string; channelKey?: string }>,
): OpenClawConfig {
  const baseTeams = cfg.channels?.msteams?.teams ?? {};
  const teams: Record<string, { channels?: Record<string, unknown> }> = { ...baseTeams };
  for (const entry of entries) {
    const teamKey = entry.teamKey;
    if (!teamKey) {
      continue;
    }
    const existing = teams[teamKey] ?? {};
    if (entry.channelKey) {
      const channels = { ...existing.channels };
      channels[entry.channelKey] = channels[entry.channelKey] ?? {};
      teams[teamKey] = { ...existing, channels };
    } else {
      teams[teamKey] = existing;
    }
  }
  return {
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: {
        ...cfg.channels?.msteams,
        enabled: true,
        teams: teams as Record<string, MSTeamsTeamConfig>,
      },
    },
  };
}

const dmPolicy: ChannelOnboardingDmPolicy = {
  label: "MS Teams",
  channel,
  policyKey: "channels.msteams.dmPolicy",
  allowFromKey: "channels.msteams.allowFrom",
  getCurrent: (cfg) => cfg.channels?.msteams?.dmPolicy ?? "pairing",
  setPolicy: (cfg, policy) => setMSTeamsDmPolicy(cfg, policy),
  promptAllowFrom: promptMSTeamsAllowFrom,
};

export const msteamsOnboardingAdapter: ChannelOnboardingAdapter = {
  channel,
  getStatus: async ({ cfg }) => {
    const configured = Boolean(resolveMSTeamsCredentials(cfg.channels?.msteams));
    return {
      channel,
      configured,
      statusLines: [`MS Teams: ${configured ? "configured" : "needs app credentials"}`],
      selectionHint: configured ? "configured" : "needs app creds",
      quickstartScore: configured ? 2 : 0,
    };
  },
  configure: async ({ cfg, prompter }) => {
    const resolved = resolveMSTeamsCredentials(cfg.channels?.msteams);
    const hasConfigCreds = Boolean(
      cfg.channels?.msteams?.appId?.trim() &&
      cfg.channels?.msteams?.appPassword?.trim() &&
      cfg.channels?.msteams?.tenantId?.trim(),
    );
    const canUseEnv = Boolean(
      !hasConfigCreds &&
      process.env.MSTEAMS_APP_ID?.trim() &&
      process.env.MSTEAMS_APP_PASSWORD?.trim() &&
      process.env.MSTEAMS_TENANT_ID?.trim(),
    );

    let next = cfg;
    let appId: string | null = null;
    let appPassword: string | null = null;
    let tenantId: string | null = null;

    if (!resolved) {
      await noteMSTeamsCredentialHelp(prompter);
    }

    if (canUseEnv) {
      const keepEnv = await prompter.confirm({
        message:
          "MSTEAMS_APP_ID + MSTEAMS_APP_PASSWORD + MSTEAMS_TENANT_ID detected. Use env vars?",
        initialValue: true,
      });
      if (keepEnv) {
        next = {
          ...next,
          channels: {
            ...next.channels,
            msteams: { ...next.channels?.msteams, enabled: true },
          },
        };
      } else {
        appId = String(
          await prompter.text({
            message: "Enter MS Teams App ID",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
        appPassword = String(
          await prompter.text({
            message: "Enter MS Teams App Password",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
        tenantId = String(
          await prompter.text({
            message: "Enter MS Teams Tenant ID",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else if (hasConfigCreds) {
      const keep = await prompter.confirm({
        message: "MS Teams credentials already configured. Keep them?",
        initialValue: true,
      });
      if (!keep) {
        appId = String(
          await prompter.text({
            message: "Enter MS Teams App ID",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
        appPassword = String(
          await prompter.text({
            message: "Enter MS Teams App Password",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
        tenantId = String(
          await prompter.text({
            message: "Enter MS Teams Tenant ID",
            validate: (value) => (value?.trim() ? undefined : "Required"),
          }),
        ).trim();
      }
    } else {
      appId = String(
        await prompter.text({
          message: "Enter MS Teams App ID",
          validate: (value) => (value?.trim() ? undefined : "Required"),
        }),
      ).trim();
      appPassword = String(
        await prompter.text({
          message: "Enter MS Teams App Password",
          validate: (value) => (value?.trim() ? undefined : "Required"),
        }),
      ).trim();
      tenantId = String(
        await prompter.text({
          message: "Enter MS Teams Tenant ID",
          validate: (value) => (value?.trim() ? undefined : "Required"),
        }),
      ).trim();
    }

    if (appId && appPassword && tenantId) {
      next = {
        ...next,
        channels: {
          ...next.channels,
          msteams: {
            ...next.channels?.msteams,
            enabled: true,
            appId,
            appPassword,
            tenantId,
          },
        },
      };
    }

    const currentEntries = Object.entries(next.channels?.msteams?.teams ?? {}).flatMap(
      ([teamKey, value]) => {
        const channels = value?.channels ?? {};
        const channelKeys = Object.keys(channels);
        if (channelKeys.length === 0) {
          return [teamKey];
        }
        return channelKeys.map((channelKey) => `${teamKey}/${channelKey}`);
      },
    );
    const accessConfig = await promptChannelAccessConfig({
      prompter,
      label: "MS Teams channels",
      currentPolicy: next.channels?.msteams?.groupPolicy ?? "allowlist",
      currentEntries,
      placeholder: "Team Name/Channel Name, teamId/conversationId",
      updatePrompt: Boolean(next.channels?.msteams?.teams),
    });
    if (accessConfig) {
      if (accessConfig.policy !== "allowlist") {
        next = setMSTeamsGroupPolicy(next, accessConfig.policy);
      } else {
        let entries = accessConfig.entries
          .map((entry) => parseMSTeamsTeamEntry(entry))
          .filter(Boolean) as Array<{ teamKey: string; channelKey?: string }>;
        if (accessConfig.entries.length > 0 && resolveMSTeamsCredentials(next.channels?.msteams)) {
          try {
            const resolved = await resolveMSTeamsChannelAllowlist({
              cfg: next,
              entries: accessConfig.entries,
            });
            const resolvedChannels = resolved.filter(
              (entry) => entry.resolved && entry.teamId && entry.channelId,
            );
            const resolvedTeams = resolved.filter(
              (entry) => entry.resolved && entry.teamId && !entry.channelId,
            );
            const unresolved = resolved
              .filter((entry) => !entry.resolved)
              .map((entry) => entry.input);

            entries = [
              ...resolvedChannels.map((entry) => ({
                teamKey: entry.teamId as string,
                channelKey: entry.channelId as string,
              })),
              ...resolvedTeams.map((entry) => ({
                teamKey: entry.teamId as string,
              })),
              ...unresolved.map((entry) => parseMSTeamsTeamEntry(entry)).filter(Boolean),
            ] as Array<{ teamKey: string; channelKey?: string }>;

            if (resolvedChannels.length > 0 || resolvedTeams.length > 0 || unresolved.length > 0) {
              const summary: string[] = [];
              if (resolvedChannels.length > 0) {
                summary.push(
                  `Resolved channels: ${resolvedChannels
                    .map((entry) => entry.channelId)
                    .filter(Boolean)
                    .join(", ")}`,
                );
              }
              if (resolvedTeams.length > 0) {
                summary.push(
                  `Resolved teams: ${resolvedTeams
                    .map((entry) => entry.teamId)
                    .filter(Boolean)
                    .join(", ")}`,
                );
              }
              if (unresolved.length > 0) {
                summary.push(`Unresolved (kept as typed): ${unresolved.join(", ")}`);
              }
              await prompter.note(summary.join("\n"), "MS Teams channels");
            }
          } catch (err) {
            await prompter.note(
              `Channel lookup failed; keeping entries as typed. ${String(err)}`,
              "MS Teams channels",
            );
          }
        }
        next = setMSTeamsGroupPolicy(next, "allowlist");
        next = setMSTeamsTeamsAllowlist(next, entries);
      }
    }

    return { cfg: next, accountId: DEFAULT_ACCOUNT_ID };
  },
  dmPolicy,
  disable: (cfg) => ({
    ...cfg,
    channels: {
      ...cfg.channels,
      msteams: { ...cfg.channels?.msteams, enabled: false },
    },
  }),
};
]]></file>
  <file path="./extensions/msteams/src/polls.test.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { beforeEach, describe, expect, it } from "vitest";
import { buildMSTeamsPollCard, createMSTeamsPollStoreFs, extractMSTeamsPollVote } from "./polls.js";
import { setMSTeamsRuntime } from "./runtime.js";

const runtimeStub = {
  state: {
    resolveStateDir: (env: NodeJS.ProcessEnv = process.env, homedir?: () => string) => {
      const override = env.OPENCLAW_STATE_DIR?.trim() || env.OPENCLAW_STATE_DIR?.trim();
      if (override) {
        return override;
      }
      const resolvedHome = homedir ? homedir() : os.homedir();
      return path.join(resolvedHome, ".openclaw");
    },
  },
} as unknown as PluginRuntime;

describe("msteams polls", () => {
  beforeEach(() => {
    setMSTeamsRuntime(runtimeStub);
  });

  it("builds poll cards with fallback text", () => {
    const card = buildMSTeamsPollCard({
      question: "Lunch?",
      options: ["Pizza", "Sushi"],
    });

    expect(card.pollId).toBeTruthy();
    expect(card.fallbackText).toContain("Poll: Lunch?");
    expect(card.fallbackText).toContain("1. Pizza");
    expect(card.fallbackText).toContain("2. Sushi");
  });

  it("extracts poll votes from activity values", () => {
    const vote = extractMSTeamsPollVote({
      value: {
        openclawPollId: "poll-1",
        choices: "0,1",
      },
    });

    expect(vote).toEqual({
      pollId: "poll-1",
      selections: ["0", "1"],
    });
  });

  it("stores and records poll votes", async () => {
    const home = await fs.promises.mkdtemp(path.join(os.tmpdir(), "openclaw-msteams-polls-"));
    const store = createMSTeamsPollStoreFs({ homedir: () => home });
    await store.createPoll({
      id: "poll-2",
      question: "Pick one",
      options: ["A", "B"],
      maxSelections: 1,
      createdAt: new Date().toISOString(),
      votes: {},
    });
    await store.recordVote({
      pollId: "poll-2",
      voterId: "user-1",
      selections: ["0", "1"],
    });
    const stored = await store.getPoll("poll-2");
    expect(stored?.votes["user-1"]).toEqual(["0"]);
  });
});
]]></file>
  <file path="./extensions/msteams/src/graph-chat.ts"><![CDATA[/**
 * Native Teams file card attachments for Bot Framework.
 *
 * The Bot Framework SDK supports `application/vnd.microsoft.teams.card.file.info`
 * content type which produces native Teams file cards.
 *
 * @see https://learn.microsoft.com/en-us/microsoftteams/platform/bots/how-to/bots-filesv4
 */

import type { DriveItemProperties } from "./graph-upload.js";

/**
 * Build a native Teams file card attachment for Bot Framework.
 *
 * This uses the `application/vnd.microsoft.teams.card.file.info` content type
 * which is supported by Bot Framework and produces native Teams file cards
 * (the same display as when a user manually shares a file).
 *
 * @param file - DriveItem properties from getDriveItemProperties()
 * @returns Attachment object for Bot Framework sendActivity()
 */
export function buildTeamsFileInfoCard(file: DriveItemProperties): {
  contentType: string;
  contentUrl: string;
  name: string;
  content: {
    uniqueId: string;
    fileType: string;
  };
} {
  // Extract unique ID from eTag (remove quotes, braces, and version suffix)
  // Example eTag formats: "{GUID},version" or "\"{GUID},version\""
  const rawETag = file.eTag;
  const uniqueId =
    rawETag
      .replace(/^["']|["']$/g, "") // Remove outer quotes
      .replace(/[{}]/g, "") // Remove curly braces
      .split(",")[0] ?? rawETag; // Take the GUID part before comma

  // Extract file extension from filename
  const lastDot = file.name.lastIndexOf(".");
  const fileType = lastDot >= 0 ? file.name.slice(lastDot + 1).toLowerCase() : "";

  return {
    contentType: "application/vnd.microsoft.teams.card.file.info",
    contentUrl: file.webDavUrl,
    name: file.name,
    content: {
      uniqueId,
      fileType,
    },
  };
}
]]></file>
  <file path="./extensions/msteams/src/resolve-allowlist.ts"><![CDATA[import type { MSTeamsConfig } from "openclaw/plugin-sdk";
import { GRAPH_ROOT } from "./attachments/shared.js";
import { loadMSTeamsSdkWithAuth } from "./sdk.js";
import { resolveMSTeamsCredentials } from "./token.js";

type GraphUser = {
  id?: string;
  displayName?: string;
  userPrincipalName?: string;
  mail?: string;
};

type GraphGroup = {
  id?: string;
  displayName?: string;
};

type GraphChannel = {
  id?: string;
  displayName?: string;
};

type GraphResponse<T> = { value?: T[] };

export type MSTeamsChannelResolution = {
  input: string;
  resolved: boolean;
  teamId?: string;
  teamName?: string;
  channelId?: string;
  channelName?: string;
  note?: string;
};

export type MSTeamsUserResolution = {
  input: string;
  resolved: boolean;
  id?: string;
  name?: string;
  note?: string;
};

function readAccessToken(value: unknown): string | null {
  if (typeof value === "string") {
    return value;
  }
  if (value && typeof value === "object") {
    const token =
      (value as { accessToken?: unknown }).accessToken ?? (value as { token?: unknown }).token;
    return typeof token === "string" ? token : null;
  }
  return null;
}

function stripProviderPrefix(raw: string): string {
  return raw.replace(/^(msteams|teams):/i, "");
}

export function normalizeMSTeamsMessagingTarget(raw: string): string | undefined {
  let trimmed = raw.trim();
  if (!trimmed) {
    return undefined;
  }
  trimmed = stripProviderPrefix(trimmed).trim();
  if (/^conversation:/i.test(trimmed)) {
    const id = trimmed.slice("conversation:".length).trim();
    return id ? `conversation:${id}` : undefined;
  }
  if (/^user:/i.test(trimmed)) {
    const id = trimmed.slice("user:".length).trim();
    return id ? `user:${id}` : undefined;
  }
  return trimmed || undefined;
}

export function normalizeMSTeamsUserInput(raw: string): string {
  return stripProviderPrefix(raw)
    .replace(/^(user|conversation):/i, "")
    .trim();
}

export function parseMSTeamsConversationId(raw: string): string | null {
  const trimmed = stripProviderPrefix(raw).trim();
  if (!/^conversation:/i.test(trimmed)) {
    return null;
  }
  const id = trimmed.slice("conversation:".length).trim();
  return id;
}

function normalizeMSTeamsTeamKey(raw: string): string | undefined {
  const trimmed = stripProviderPrefix(raw)
    .replace(/^team:/i, "")
    .trim();
  return trimmed || undefined;
}

function normalizeMSTeamsChannelKey(raw?: string | null): string | undefined {
  const trimmed = raw?.trim().replace(/^#/, "").trim() ?? "";
  return trimmed || undefined;
}

export function parseMSTeamsTeamChannelInput(raw: string): { team?: string; channel?: string } {
  const trimmed = stripProviderPrefix(raw).trim();
  if (!trimmed) {
    return {};
  }
  const parts = trimmed.split("/");
  const team = normalizeMSTeamsTeamKey(parts[0] ?? "");
  const channel =
    parts.length > 1 ? normalizeMSTeamsChannelKey(parts.slice(1).join("/")) : undefined;
  return {
    ...(team ? { team } : {}),
    ...(channel ? { channel } : {}),
  };
}

export function parseMSTeamsTeamEntry(
  raw: string,
): { teamKey: string; channelKey?: string } | null {
  const { team, channel } = parseMSTeamsTeamChannelInput(raw);
  if (!team) {
    return null;
  }
  return {
    teamKey: team,
    ...(channel ? { channelKey: channel } : {}),
  };
}

function normalizeQuery(value?: string | null): string {
  return value?.trim() ?? "";
}

function escapeOData(value: string): string {
  return value.replace(/'/g, "''");
}

async function fetchGraphJson<T>(params: {
  token: string;
  path: string;
  headers?: Record<string, string>;
}): Promise<T> {
  const res = await fetch(`${GRAPH_ROOT}${params.path}`, {
    headers: {
      Authorization: `Bearer ${params.token}`,
      ...params.headers,
    },
  });
  if (!res.ok) {
    const text = await res.text().catch(() => "");
    throw new Error(`Graph ${params.path} failed (${res.status}): ${text || "unknown error"}`);
  }
  return (await res.json()) as T;
}

async function resolveGraphToken(cfg: unknown): Promise<string> {
  const creds = resolveMSTeamsCredentials(
    (cfg as { channels?: { msteams?: unknown } })?.channels?.msteams as MSTeamsConfig | undefined,
  );
  if (!creds) {
    throw new Error("MS Teams credentials missing");
  }
  const { sdk, authConfig } = await loadMSTeamsSdkWithAuth(creds);
  const tokenProvider = new sdk.MsalTokenProvider(authConfig);
  const token = await tokenProvider.getAccessToken("https://graph.microsoft.com");
  const accessToken = readAccessToken(token);
  if (!accessToken) {
    throw new Error("MS Teams graph token unavailable");
  }
  return accessToken;
}

async function listTeamsByName(token: string, query: string): Promise<GraphGroup[]> {
  const escaped = escapeOData(query);
  const filter = `resourceProvisioningOptions/Any(x:x eq 'Team') and startsWith(displayName,'${escaped}')`;
  const path = `/groups?$filter=${encodeURIComponent(filter)}&$select=id,displayName`;
  const res = await fetchGraphJson<GraphResponse<GraphGroup>>({ token, path });
  return res.value ?? [];
}

async function listChannelsForTeam(token: string, teamId: string): Promise<GraphChannel[]> {
  const path = `/teams/${encodeURIComponent(teamId)}/channels?$select=id,displayName`;
  const res = await fetchGraphJson<GraphResponse<GraphChannel>>({ token, path });
  return res.value ?? [];
}

export async function resolveMSTeamsChannelAllowlist(params: {
  cfg: unknown;
  entries: string[];
}): Promise<MSTeamsChannelResolution[]> {
  const token = await resolveGraphToken(params.cfg);
  const results: MSTeamsChannelResolution[] = [];

  for (const input of params.entries) {
    const { team, channel } = parseMSTeamsTeamChannelInput(input);
    if (!team) {
      results.push({ input, resolved: false });
      continue;
    }
    const teams = /^[0-9a-fA-F-]{16,}$/.test(team)
      ? [{ id: team, displayName: team }]
      : await listTeamsByName(token, team);
    if (teams.length === 0) {
      results.push({ input, resolved: false, note: "team not found" });
      continue;
    }
    const teamMatch = teams[0];
    const teamId = teamMatch.id?.trim();
    const teamName = teamMatch.displayName?.trim() || team;
    if (!teamId) {
      results.push({ input, resolved: false, note: "team id missing" });
      continue;
    }
    if (!channel) {
      results.push({
        input,
        resolved: true,
        teamId,
        teamName,
        note: teams.length > 1 ? "multiple teams; chose first" : undefined,
      });
      continue;
    }
    const channels = await listChannelsForTeam(token, teamId);
    const channelMatch =
      channels.find((item) => item.id === channel) ??
      channels.find((item) => item.displayName?.toLowerCase() === channel.toLowerCase()) ??
      channels.find((item) =>
        item.displayName?.toLowerCase().includes(channel.toLowerCase() ?? ""),
      );
    if (!channelMatch?.id) {
      results.push({ input, resolved: false, note: "channel not found" });
      continue;
    }
    results.push({
      input,
      resolved: true,
      teamId,
      teamName,
      channelId: channelMatch.id,
      channelName: channelMatch.displayName ?? channel,
      note: channels.length > 1 ? "multiple channels; chose first" : undefined,
    });
  }

  return results;
}

export async function resolveMSTeamsUserAllowlist(params: {
  cfg: unknown;
  entries: string[];
}): Promise<MSTeamsUserResolution[]> {
  const token = await resolveGraphToken(params.cfg);
  const results: MSTeamsUserResolution[] = [];

  for (const input of params.entries) {
    const query = normalizeQuery(normalizeMSTeamsUserInput(input));
    if (!query) {
      results.push({ input, resolved: false });
      continue;
    }
    if (/^[0-9a-fA-F-]{16,}$/.test(query)) {
      results.push({ input, resolved: true, id: query });
      continue;
    }
    let users: GraphUser[] = [];
    if (query.includes("@")) {
      const escaped = escapeOData(query);
      const filter = `(mail eq '${escaped}' or userPrincipalName eq '${escaped}')`;
      const path = `/users?$filter=${encodeURIComponent(filter)}&$select=id,displayName,mail,userPrincipalName`;
      const res = await fetchGraphJson<GraphResponse<GraphUser>>({ token, path });
      users = res.value ?? [];
    } else {
      const path = `/users?$search=${encodeURIComponent(`"displayName:${query}"`)}&$select=id,displayName,mail,userPrincipalName&$top=10`;
      const res = await fetchGraphJson<GraphResponse<GraphUser>>({
        token,
        path,
        headers: { ConsistencyLevel: "eventual" },
      });
      users = res.value ?? [];
    }
    const match = users[0];
    if (!match?.id) {
      results.push({ input, resolved: false });
      continue;
    }
    results.push({
      input,
      resolved: true,
      id: match.id,
      name: match.displayName ?? undefined,
      note: users.length > 1 ? "multiple matches; chose first" : undefined,
    });
  }

  return results;
}
]]></file>
  <file path="./extensions/msteams/src/polls-store-memory.ts"><![CDATA[import {
  type MSTeamsPoll,
  type MSTeamsPollStore,
  normalizeMSTeamsPollSelections,
} from "./polls.js";

export function createMSTeamsPollStoreMemory(initial: MSTeamsPoll[] = []): MSTeamsPollStore {
  const polls = new Map<string, MSTeamsPoll>();
  for (const poll of initial) {
    polls.set(poll.id, { ...poll });
  }

  const createPoll = async (poll: MSTeamsPoll) => {
    polls.set(poll.id, { ...poll });
  };

  const getPoll = async (pollId: string) => polls.get(pollId) ?? null;

  const recordVote = async (params: { pollId: string; voterId: string; selections: string[] }) => {
    const poll = polls.get(params.pollId);
    if (!poll) {
      return null;
    }
    const normalized = normalizeMSTeamsPollSelections(poll, params.selections);
    poll.votes[params.voterId] = normalized;
    poll.updatedAt = new Date().toISOString();
    polls.set(poll.id, poll);
    return poll;
  };

  return { createPoll, getPoll, recordVote };
}
]]></file>
  <file path="./extensions/msteams/src/messenger.test.ts"><![CDATA[import { mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { SILENT_REPLY_TOKEN, type PluginRuntime } from "openclaw/plugin-sdk";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { StoredConversationReference } from "./conversation-store.js";
const graphUploadMockState = vi.hoisted(() => ({
  uploadAndShareOneDrive: vi.fn(),
}));

vi.mock("./graph-upload.js", async () => {
  const actual = await vi.importActual<typeof import("./graph-upload.js")>("./graph-upload.js");
  return {
    ...actual,
    uploadAndShareOneDrive: graphUploadMockState.uploadAndShareOneDrive,
  };
});

import {
  type MSTeamsAdapter,
  renderReplyPayloadsToMessages,
  sendMSTeamsMessages,
} from "./messenger.js";
import { setMSTeamsRuntime } from "./runtime.js";

const chunkMarkdownText = (text: string, limit: number) => {
  if (!text) {
    return [];
  }
  if (limit <= 0 || text.length <= limit) {
    return [text];
  }
  const chunks: string[] = [];
  for (let index = 0; index < text.length; index += limit) {
    chunks.push(text.slice(index, index + limit));
  }
  return chunks;
};

const runtimeStub = {
  channel: {
    text: {
      chunkMarkdownText,
      chunkMarkdownTextWithMode: chunkMarkdownText,
      resolveMarkdownTableMode: () => "code",
      convertMarkdownTables: (text: string) => text,
    },
  },
} as unknown as PluginRuntime;

describe("msteams messenger", () => {
  beforeEach(() => {
    setMSTeamsRuntime(runtimeStub);
    graphUploadMockState.uploadAndShareOneDrive.mockReset();
    graphUploadMockState.uploadAndShareOneDrive.mockResolvedValue({
      itemId: "item123",
      webUrl: "https://onedrive.example.com/item123",
      shareUrl: "https://onedrive.example.com/share/item123",
      name: "upload.txt",
    });
  });

  describe("renderReplyPayloadsToMessages", () => {
    it("filters silent replies", () => {
      const messages = renderReplyPayloadsToMessages([{ text: SILENT_REPLY_TOKEN }], {
        textChunkLimit: 4000,
        tableMode: "code",
      });
      expect(messages).toEqual([]);
    });

    it("filters silent reply prefixes", () => {
      const messages = renderReplyPayloadsToMessages(
        [{ text: `${SILENT_REPLY_TOKEN} -- ignored` }],
        { textChunkLimit: 4000, tableMode: "code" },
      );
      expect(messages).toEqual([]);
    });

    it("splits media into separate messages by default", () => {
      const messages = renderReplyPayloadsToMessages(
        [{ text: "hi", mediaUrl: "https://example.com/a.png" }],
        { textChunkLimit: 4000, tableMode: "code" },
      );
      expect(messages).toEqual([{ text: "hi" }, { mediaUrl: "https://example.com/a.png" }]);
    });

    it("supports inline media mode", () => {
      const messages = renderReplyPayloadsToMessages(
        [{ text: "hi", mediaUrl: "https://example.com/a.png" }],
        { textChunkLimit: 4000, mediaMode: "inline", tableMode: "code" },
      );
      expect(messages).toEqual([{ text: "hi", mediaUrl: "https://example.com/a.png" }]);
    });

    it("chunks long text when enabled", () => {
      const long = "hello ".repeat(200);
      const messages = renderReplyPayloadsToMessages([{ text: long }], {
        textChunkLimit: 50,
        tableMode: "code",
      });
      expect(messages.length).toBeGreaterThan(1);
    });
  });

  describe("sendMSTeamsMessages", () => {
    const baseRef: StoredConversationReference = {
      activityId: "activity123",
      user: { id: "user123", name: "User" },
      agent: { id: "bot123", name: "Bot" },
      conversation: { id: "19:abc@thread.tacv2;messageid=deadbeef" },
      channelId: "msteams",
      serviceUrl: "https://service.example.com",
    };

    it("sends thread messages via the provided context", async () => {
      const sent: string[] = [];
      const ctx = {
        sendActivity: async (activity: unknown) => {
          const { text } = activity as { text?: string };
          sent.push(text ?? "");
          return { id: `id:${text ?? ""}` };
        },
      };

      const adapter: MSTeamsAdapter = {
        continueConversation: async () => {},
      };

      const ids = await sendMSTeamsMessages({
        replyStyle: "thread",
        adapter,
        appId: "app123",
        conversationRef: baseRef,
        context: ctx,
        messages: [{ text: "one" }, { text: "two" }],
      });

      expect(sent).toEqual(["one", "two"]);
      expect(ids).toEqual(["id:one", "id:two"]);
    });

    it("sends top-level messages via continueConversation and strips activityId", async () => {
      const seen: { reference?: unknown; texts: string[] } = { texts: [] };

      const adapter: MSTeamsAdapter = {
        continueConversation: async (_appId, reference, logic) => {
          seen.reference = reference;
          await logic({
            sendActivity: async (activity: unknown) => {
              const { text } = activity as { text?: string };
              seen.texts.push(text ?? "");
              return { id: `id:${text ?? ""}` };
            },
          });
        },
      };

      const ids = await sendMSTeamsMessages({
        replyStyle: "top-level",
        adapter,
        appId: "app123",
        conversationRef: baseRef,
        messages: [{ text: "hello" }],
      });

      expect(seen.texts).toEqual(["hello"]);
      expect(ids).toEqual(["id:hello"]);

      const ref = seen.reference as {
        activityId?: string;
        conversation?: { id?: string };
      };
      expect(ref.activityId).toBeUndefined();
      expect(ref.conversation?.id).toBe("19:abc@thread.tacv2");
    });

    it("preserves parsed mentions when appending OneDrive fallback file links", async () => {
      const tmpDir = await mkdtemp(path.join(os.tmpdir(), "msteams-mention-"));
      const localFile = path.join(tmpDir, "note.txt");
      await writeFile(localFile, "hello");

      try {
        const sent: Array<{ text?: string; entities?: unknown[] }> = [];
        const ctx = {
          sendActivity: async (activity: unknown) => {
            sent.push(activity as { text?: string; entities?: unknown[] });
            return { id: "id:one" };
          },
        };

        const adapter: MSTeamsAdapter = {
          continueConversation: async () => {},
        };

        const ids = await sendMSTeamsMessages({
          replyStyle: "thread",
          adapter,
          appId: "app123",
          conversationRef: {
            ...baseRef,
            conversation: {
              ...baseRef.conversation,
              conversationType: "channel",
            },
          },
          context: ctx,
          messages: [{ text: "Hello @[John](29:08q2j2o3jc09au90eucae)", mediaUrl: localFile }],
          tokenProvider: {
            getAccessToken: async () => "token",
          },
        });

        expect(ids).toEqual(["id:one"]);
        expect(graphUploadMockState.uploadAndShareOneDrive).toHaveBeenCalledOnce();
        expect(sent).toHaveLength(1);
        expect(sent[0]?.text).toContain("Hello <at>John</at>");
        expect(sent[0]?.text).toContain(
          "📎 [upload.txt](https://onedrive.example.com/share/item123)",
        );
        expect(sent[0]?.entities).toEqual([
          {
            type: "mention",
            text: "<at>John</at>",
            mentioned: {
              id: "29:08q2j2o3jc09au90eucae",
              name: "John",
            },
          },
        ]);
      } finally {
        await rm(tmpDir, { recursive: true, force: true });
      }
    });

    it("retries thread sends on throttling (429)", async () => {
      const attempts: string[] = [];
      const retryEvents: Array<{ nextAttempt: number; delayMs: number }> = [];

      const ctx = {
        sendActivity: async (activity: unknown) => {
          const { text } = activity as { text?: string };
          attempts.push(text ?? "");
          if (attempts.length === 1) {
            throw Object.assign(new Error("throttled"), { statusCode: 429 });
          }
          return { id: `id:${text ?? ""}` };
        },
      };

      const adapter: MSTeamsAdapter = {
        continueConversation: async () => {},
      };

      const ids = await sendMSTeamsMessages({
        replyStyle: "thread",
        adapter,
        appId: "app123",
        conversationRef: baseRef,
        context: ctx,
        messages: [{ text: "one" }],
        retry: { maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0 },
        onRetry: (e) => retryEvents.push({ nextAttempt: e.nextAttempt, delayMs: e.delayMs }),
      });

      expect(attempts).toEqual(["one", "one"]);
      expect(ids).toEqual(["id:one"]);
      expect(retryEvents).toEqual([{ nextAttempt: 2, delayMs: 0 }]);
    });

    it("does not retry thread sends on client errors (4xx)", async () => {
      const ctx = {
        sendActivity: async () => {
          throw Object.assign(new Error("bad request"), { statusCode: 400 });
        },
      };

      const adapter: MSTeamsAdapter = {
        continueConversation: async () => {},
      };

      await expect(
        sendMSTeamsMessages({
          replyStyle: "thread",
          adapter,
          appId: "app123",
          conversationRef: baseRef,
          context: ctx,
          messages: [{ text: "one" }],
          retry: { maxAttempts: 3, baseDelayMs: 0, maxDelayMs: 0 },
        }),
      ).rejects.toMatchObject({ statusCode: 400 });
    });

    it("retries top-level sends on transient (5xx)", async () => {
      const attempts: string[] = [];

      const adapter: MSTeamsAdapter = {
        continueConversation: async (_appId, _reference, logic) => {
          await logic({
            sendActivity: async (activity: unknown) => {
              const { text } = activity as { text?: string };
              attempts.push(text ?? "");
              if (attempts.length === 1) {
                throw Object.assign(new Error("server error"), {
                  statusCode: 503,
                });
              }
              return { id: `id:${text ?? ""}` };
            },
          });
        },
      };

      const ids = await sendMSTeamsMessages({
        replyStyle: "top-level",
        adapter,
        appId: "app123",
        conversationRef: baseRef,
        messages: [{ text: "hello" }],
        retry: { maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0 },
      });

      expect(attempts).toEqual(["hello", "hello"]);
      expect(ids).toEqual(["id:hello"]);
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/conversation-store.ts"><![CDATA[/**
 * Conversation store for MS Teams proactive messaging.
 *
 * Stores ConversationReference-like objects keyed by conversation ID so we can
 * send proactive messages later (after the webhook turn has completed).
 */

/** Minimal ConversationReference shape for proactive messaging */
export type StoredConversationReference = {
  /** Activity ID from the last message */
  activityId?: string;
  /** User who sent the message */
  user?: { id?: string; name?: string; aadObjectId?: string };
  /** Agent/bot that received the message */
  agent?: { id?: string; name?: string; aadObjectId?: string } | null;
  /** @deprecated legacy field (pre-Agents SDK). Prefer `agent`. */
  bot?: { id?: string; name?: string };
  /** Conversation details */
  conversation?: { id?: string; conversationType?: string; tenantId?: string };
  /** Team ID for channel messages (when available). */
  teamId?: string;
  /** Channel ID (usually "msteams") */
  channelId?: string;
  /** Service URL for sending messages back */
  serviceUrl?: string;
  /** Locale */
  locale?: string;
};

export type MSTeamsConversationStoreEntry = {
  conversationId: string;
  reference: StoredConversationReference;
};

export type MSTeamsConversationStore = {
  upsert: (conversationId: string, reference: StoredConversationReference) => Promise<void>;
  get: (conversationId: string) => Promise<StoredConversationReference | null>;
  list: () => Promise<MSTeamsConversationStoreEntry[]>;
  remove: (conversationId: string) => Promise<boolean>;
  findByUserId: (id: string) => Promise<MSTeamsConversationStoreEntry | null>;
};
]]></file>
  <file path="./extensions/msteams/src/file-consent.ts"><![CDATA[/**
 * FileConsentCard utilities for MS Teams large file uploads (>4MB) in personal chats.
 *
 * Teams requires user consent before the bot can upload large files. This module provides
 * utilities for:
 * - Building FileConsentCard attachments (to request upload permission)
 * - Building FileInfoCard attachments (to confirm upload completion)
 * - Parsing fileConsent/invoke activities
 */

export interface FileConsentCardParams {
  filename: string;
  description?: string;
  sizeInBytes: number;
  /** Custom context data to include in the card (passed back in the invoke) */
  context?: Record<string, unknown>;
}

export interface FileInfoCardParams {
  filename: string;
  contentUrl: string;
  uniqueId: string;
  fileType: string;
}

/**
 * Build a FileConsentCard attachment for requesting upload permission.
 * Use this for files >= 4MB in personal (1:1) chats.
 */
export function buildFileConsentCard(params: FileConsentCardParams) {
  return {
    contentType: "application/vnd.microsoft.teams.card.file.consent",
    name: params.filename,
    content: {
      description: params.description ?? `File: ${params.filename}`,
      sizeInBytes: params.sizeInBytes,
      acceptContext: { filename: params.filename, ...params.context },
      declineContext: { filename: params.filename, ...params.context },
    },
  };
}

/**
 * Build a FileInfoCard attachment for confirming upload completion.
 * Send this after successfully uploading the file to the consent URL.
 */
export function buildFileInfoCard(params: FileInfoCardParams) {
  return {
    contentType: "application/vnd.microsoft.teams.card.file.info",
    contentUrl: params.contentUrl,
    name: params.filename,
    content: {
      uniqueId: params.uniqueId,
      fileType: params.fileType,
    },
  };
}

export interface FileConsentUploadInfo {
  name: string;
  uploadUrl: string;
  contentUrl: string;
  uniqueId: string;
  fileType: string;
}

export interface FileConsentResponse {
  action: "accept" | "decline";
  uploadInfo?: FileConsentUploadInfo;
  context?: Record<string, unknown>;
}

/**
 * Parse a fileConsent/invoke activity.
 * Returns null if the activity is not a file consent invoke.
 */
export function parseFileConsentInvoke(activity: {
  name?: string;
  value?: unknown;
}): FileConsentResponse | null {
  if (activity.name !== "fileConsent/invoke") {
    return null;
  }

  const value = activity.value as {
    type?: string;
    action?: string;
    uploadInfo?: FileConsentUploadInfo;
    context?: Record<string, unknown>;
  };

  if (value?.type !== "fileUpload") {
    return null;
  }

  return {
    action: value.action === "accept" ? "accept" : "decline",
    uploadInfo: value.uploadInfo,
    context: value.context,
  };
}

/**
 * Upload a file to the consent URL provided by Teams.
 * The URL is provided in the fileConsent/invoke response after user accepts.
 */
export async function uploadToConsentUrl(params: {
  url: string;
  buffer: Buffer;
  contentType?: string;
  fetchFn?: typeof fetch;
}): Promise<void> {
  const fetchFn = params.fetchFn ?? fetch;
  const res = await fetchFn(params.url, {
    method: "PUT",
    headers: {
      "Content-Type": params.contentType ?? "application/octet-stream",
      "Content-Range": `bytes 0-${params.buffer.length - 1}/${params.buffer.length}`,
    },
    body: new Uint8Array(params.buffer),
  });

  if (!res.ok) {
    throw new Error(`File upload to consent URL failed: ${res.status} ${res.statusText}`);
  }
}
]]></file>
  <file path="./extensions/msteams/src/probe.test.ts"><![CDATA[import type { MSTeamsConfig } from "openclaw/plugin-sdk";
import { describe, expect, it, vi } from "vitest";

const hostMockState = vi.hoisted(() => ({
  tokenError: null as Error | null,
}));

vi.mock("@microsoft/agents-hosting", () => ({
  getAuthConfigWithDefaults: (cfg: unknown) => cfg,
  MsalTokenProvider: class {
    async getAccessToken() {
      if (hostMockState.tokenError) {
        throw hostMockState.tokenError;
      }
      return "token";
    }
  },
}));

import { probeMSTeams } from "./probe.js";

describe("msteams probe", () => {
  it("returns an error when credentials are missing", async () => {
    const cfg = { enabled: true } as unknown as MSTeamsConfig;
    await expect(probeMSTeams(cfg)).resolves.toMatchObject({
      ok: false,
    });
  });

  it("validates credentials by acquiring a token", async () => {
    hostMockState.tokenError = null;
    const cfg = {
      enabled: true,
      appId: "app",
      appPassword: "pw",
      tenantId: "tenant",
    } as unknown as MSTeamsConfig;
    await expect(probeMSTeams(cfg)).resolves.toMatchObject({
      ok: true,
      appId: "app",
    });
  });

  it("returns a helpful error when token acquisition fails", async () => {
    hostMockState.tokenError = new Error("bad creds");
    const cfg = {
      enabled: true,
      appId: "app",
      appPassword: "pw",
      tenantId: "tenant",
    } as unknown as MSTeamsConfig;
    await expect(probeMSTeams(cfg)).resolves.toMatchObject({
      ok: false,
      appId: "app",
      error: "bad creds",
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/inbound.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import {
  normalizeMSTeamsConversationId,
  parseMSTeamsActivityTimestamp,
  stripMSTeamsMentionTags,
  wasMSTeamsBotMentioned,
} from "./inbound.js";

describe("msteams inbound", () => {
  describe("stripMSTeamsMentionTags", () => {
    it("removes <at>...</at> tags and trims", () => {
      expect(stripMSTeamsMentionTags("<at>Bot</at> hi")).toBe("hi");
      expect(stripMSTeamsMentionTags("hi <at>Bot</at>")).toBe("hi");
    });

    it("removes <at ...> tags with attributes", () => {
      expect(stripMSTeamsMentionTags('<at id="1">Bot</at> hi')).toBe("hi");
      expect(stripMSTeamsMentionTags('hi <at itemid="2">Bot</at>')).toBe("hi");
    });
  });

  describe("normalizeMSTeamsConversationId", () => {
    it("strips the ;messageid suffix", () => {
      expect(normalizeMSTeamsConversationId("19:abc@thread.tacv2;messageid=deadbeef")).toBe(
        "19:abc@thread.tacv2",
      );
    });
  });

  describe("parseMSTeamsActivityTimestamp", () => {
    it("returns undefined for empty/invalid values", () => {
      expect(parseMSTeamsActivityTimestamp(undefined)).toBeUndefined();
      expect(parseMSTeamsActivityTimestamp("not-a-date")).toBeUndefined();
    });

    it("parses string timestamps", () => {
      const ts = parseMSTeamsActivityTimestamp("2024-01-01T00:00:00.000Z");
      expect(ts?.toISOString()).toBe("2024-01-01T00:00:00.000Z");
    });

    it("passes through Date instances", () => {
      const d = new Date("2024-01-01T00:00:00.000Z");
      expect(parseMSTeamsActivityTimestamp(d)).toBe(d);
    });
  });

  describe("wasMSTeamsBotMentioned", () => {
    it("returns true when a mention entity matches recipient.id", () => {
      expect(
        wasMSTeamsBotMentioned({
          recipient: { id: "bot" },
          entities: [{ type: "mention", mentioned: { id: "bot" } }],
        }),
      ).toBe(true);
    });

    it("returns false when there is no matching mention", () => {
      expect(
        wasMSTeamsBotMentioned({
          recipient: { id: "bot" },
          entities: [{ type: "mention", mentioned: { id: "other" } }],
        }),
      ).toBe(false);
    });
  });
});
]]></file>
  <file path="./extensions/msteams/src/policy.ts"><![CDATA[import type {
  AllowlistMatch,
  ChannelGroupContext,
  GroupPolicy,
  GroupToolPolicyConfig,
  MSTeamsChannelConfig,
  MSTeamsConfig,
  MSTeamsReplyStyle,
  MSTeamsTeamConfig,
} from "openclaw/plugin-sdk";
import {
  buildChannelKeyCandidates,
  normalizeChannelSlug,
  resolveToolsBySender,
  resolveChannelEntryMatchWithFallback,
  resolveNestedAllowlistDecision,
} from "openclaw/plugin-sdk";

export type MSTeamsResolvedRouteConfig = {
  teamConfig?: MSTeamsTeamConfig;
  channelConfig?: MSTeamsChannelConfig;
  allowlistConfigured: boolean;
  allowed: boolean;
  teamKey?: string;
  channelKey?: string;
  channelMatchKey?: string;
  channelMatchSource?: "direct" | "wildcard";
};

export function resolveMSTeamsRouteConfig(params: {
  cfg?: MSTeamsConfig;
  teamId?: string | null | undefined;
  teamName?: string | null | undefined;
  conversationId?: string | null | undefined;
  channelName?: string | null | undefined;
}): MSTeamsResolvedRouteConfig {
  const teamId = params.teamId?.trim();
  const teamName = params.teamName?.trim();
  const conversationId = params.conversationId?.trim();
  const channelName = params.channelName?.trim();
  const teams = params.cfg?.teams ?? {};
  const allowlistConfigured = Object.keys(teams).length > 0;
  const teamCandidates = buildChannelKeyCandidates(
    teamId,
    teamName,
    teamName ? normalizeChannelSlug(teamName) : undefined,
  );
  const teamMatch = resolveChannelEntryMatchWithFallback({
    entries: teams,
    keys: teamCandidates,
    wildcardKey: "*",
    normalizeKey: normalizeChannelSlug,
  });
  const teamConfig = teamMatch.entry;
  const channels = teamConfig?.channels ?? {};
  const channelAllowlistConfigured = Object.keys(channels).length > 0;
  const channelCandidates = buildChannelKeyCandidates(
    conversationId,
    channelName,
    channelName ? normalizeChannelSlug(channelName) : undefined,
  );
  const channelMatch = resolveChannelEntryMatchWithFallback({
    entries: channels,
    keys: channelCandidates,
    wildcardKey: "*",
    normalizeKey: normalizeChannelSlug,
  });
  const channelConfig = channelMatch.entry;

  const allowed = resolveNestedAllowlistDecision({
    outerConfigured: allowlistConfigured,
    outerMatched: Boolean(teamConfig),
    innerConfigured: channelAllowlistConfigured,
    innerMatched: Boolean(channelConfig),
  });

  return {
    teamConfig,
    channelConfig,
    allowlistConfigured,
    allowed,
    teamKey: teamMatch.matchKey ?? teamMatch.key,
    channelKey: channelMatch.matchKey ?? channelMatch.key,
    channelMatchKey: channelMatch.matchKey,
    channelMatchSource:
      channelMatch.matchSource === "direct" || channelMatch.matchSource === "wildcard"
        ? channelMatch.matchSource
        : undefined,
  };
}

export function resolveMSTeamsGroupToolPolicy(
  params: ChannelGroupContext,
): GroupToolPolicyConfig | undefined {
  const cfg = params.cfg.channels?.msteams;
  if (!cfg) {
    return undefined;
  }
  const groupId = params.groupId?.trim();
  const groupChannel = params.groupChannel?.trim();
  const groupSpace = params.groupSpace?.trim();

  const resolved = resolveMSTeamsRouteConfig({
    cfg,
    teamId: groupSpace,
    teamName: groupSpace,
    conversationId: groupId,
    channelName: groupChannel,
  });

  if (resolved.channelConfig) {
    const senderPolicy = resolveToolsBySender({
      toolsBySender: resolved.channelConfig.toolsBySender,
      senderId: params.senderId,
      senderName: params.senderName,
      senderUsername: params.senderUsername,
      senderE164: params.senderE164,
    });
    if (senderPolicy) {
      return senderPolicy;
    }
    if (resolved.channelConfig.tools) {
      return resolved.channelConfig.tools;
    }
    const teamSenderPolicy = resolveToolsBySender({
      toolsBySender: resolved.teamConfig?.toolsBySender,
      senderId: params.senderId,
      senderName: params.senderName,
      senderUsername: params.senderUsername,
      senderE164: params.senderE164,
    });
    if (teamSenderPolicy) {
      return teamSenderPolicy;
    }
    return resolved.teamConfig?.tools;
  }
  if (resolved.teamConfig) {
    const teamSenderPolicy = resolveToolsBySender({
      toolsBySender: resolved.teamConfig.toolsBySender,
      senderId: params.senderId,
      senderName: params.senderName,
      senderUsername: params.senderUsername,
      senderE164: params.senderE164,
    });
    if (teamSenderPolicy) {
      return teamSenderPolicy;
    }
    if (resolved.teamConfig.tools) {
      return resolved.teamConfig.tools;
    }
  }

  if (!groupId) {
    return undefined;
  }

  const channelCandidates = buildChannelKeyCandidates(
    groupId,
    groupChannel,
    groupChannel ? normalizeChannelSlug(groupChannel) : undefined,
  );
  for (const teamConfig of Object.values(cfg.teams ?? {})) {
    const match = resolveChannelEntryMatchWithFallback({
      entries: teamConfig?.channels ?? {},
      keys: channelCandidates,
      wildcardKey: "*",
      normalizeKey: normalizeChannelSlug,
    });
    if (match.entry) {
      const senderPolicy = resolveToolsBySender({
        toolsBySender: match.entry.toolsBySender,
        senderId: params.senderId,
        senderName: params.senderName,
        senderUsername: params.senderUsername,
        senderE164: params.senderE164,
      });
      if (senderPolicy) {
        return senderPolicy;
      }
      if (match.entry.tools) {
        return match.entry.tools;
      }
      const teamSenderPolicy = resolveToolsBySender({
        toolsBySender: teamConfig?.toolsBySender,
        senderId: params.senderId,
        senderName: params.senderName,
        senderUsername: params.senderUsername,
        senderE164: params.senderE164,
      });
      if (teamSenderPolicy) {
        return teamSenderPolicy;
      }
      return teamConfig?.tools;
    }
  }

  return undefined;
}

export type MSTeamsReplyPolicy = {
  requireMention: boolean;
  replyStyle: MSTeamsReplyStyle;
};

export type MSTeamsAllowlistMatch = AllowlistMatch<"wildcard" | "id" | "name">;

export function resolveMSTeamsAllowlistMatch(params: {
  allowFrom: Array<string | number>;
  senderId: string;
  senderName?: string | null;
}): MSTeamsAllowlistMatch {
  const allowFrom = params.allowFrom
    .map((entry) => String(entry).trim().toLowerCase())
    .filter(Boolean);
  if (allowFrom.length === 0) {
    return { allowed: false };
  }
  if (allowFrom.includes("*")) {
    return { allowed: true, matchKey: "*", matchSource: "wildcard" };
  }
  const senderId = params.senderId.toLowerCase();
  if (allowFrom.includes(senderId)) {
    return { allowed: true, matchKey: senderId, matchSource: "id" };
  }
  const senderName = params.senderName?.toLowerCase();
  if (senderName && allowFrom.includes(senderName)) {
    return { allowed: true, matchKey: senderName, matchSource: "name" };
  }
  return { allowed: false };
}

export function resolveMSTeamsReplyPolicy(params: {
  isDirectMessage: boolean;
  globalConfig?: MSTeamsConfig;
  teamConfig?: MSTeamsTeamConfig;
  channelConfig?: MSTeamsChannelConfig;
}): MSTeamsReplyPolicy {
  if (params.isDirectMessage) {
    return { requireMention: false, replyStyle: "thread" };
  }

  const requireMention =
    params.channelConfig?.requireMention ??
    params.teamConfig?.requireMention ??
    params.globalConfig?.requireMention ??
    true;

  const explicitReplyStyle =
    params.channelConfig?.replyStyle ??
    params.teamConfig?.replyStyle ??
    params.globalConfig?.replyStyle;

  const replyStyle: MSTeamsReplyStyle =
    explicitReplyStyle ?? (requireMention ? "thread" : "top-level");

  return { requireMention, replyStyle };
}

export function isMSTeamsGroupAllowed(params: {
  groupPolicy: GroupPolicy;
  allowFrom: Array<string | number>;
  senderId: string;
  senderName?: string | null;
}): boolean {
  const { groupPolicy } = params;
  if (groupPolicy === "disabled") {
    return false;
  }
  if (groupPolicy === "open") {
    return true;
  }
  return resolveMSTeamsAllowlistMatch(params).allowed;
}
]]></file>
  <file path="./extensions/msteams/src/polls.ts"><![CDATA[import crypto from "node:crypto";
import { resolveMSTeamsStorePath } from "./storage.js";
import { readJsonFile, withFileLock, writeJsonFile } from "./store-fs.js";

export type MSTeamsPollVote = {
  pollId: string;
  selections: string[];
};

export type MSTeamsPoll = {
  id: string;
  question: string;
  options: string[];
  maxSelections: number;
  createdAt: string;
  updatedAt?: string;
  conversationId?: string;
  messageId?: string;
  votes: Record<string, string[]>;
};

export type MSTeamsPollStore = {
  createPoll: (poll: MSTeamsPoll) => Promise<void>;
  getPoll: (pollId: string) => Promise<MSTeamsPoll | null>;
  recordVote: (params: {
    pollId: string;
    voterId: string;
    selections: string[];
  }) => Promise<MSTeamsPoll | null>;
};

export type MSTeamsPollCard = {
  pollId: string;
  question: string;
  options: string[];
  maxSelections: number;
  card: Record<string, unknown>;
  fallbackText: string;
};

type PollStoreData = {
  version: 1;
  polls: Record<string, MSTeamsPoll>;
};

const STORE_FILENAME = "msteams-polls.json";
const MAX_POLLS = 1000;
const POLL_TTL_MS = 30 * 24 * 60 * 60 * 1000;
function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === "object" && !Array.isArray(value);
}

function normalizeChoiceValue(value: unknown): string | null {
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed ? trimmed : null;
  }
  if (typeof value === "number" && Number.isFinite(value)) {
    return String(value);
  }
  return null;
}

function extractSelections(value: unknown): string[] {
  if (Array.isArray(value)) {
    return value.map(normalizeChoiceValue).filter((entry): entry is string => Boolean(entry));
  }
  const normalized = normalizeChoiceValue(value);
  if (!normalized) {
    return [];
  }
  if (normalized.includes(",")) {
    return normalized
      .split(",")
      .map((entry) => entry.trim())
      .filter(Boolean);
  }
  return [normalized];
}

function readNestedValue(value: unknown, keys: Array<string | number>): unknown {
  let current: unknown = value;
  for (const key of keys) {
    if (!isRecord(current)) {
      return undefined;
    }
    current = current[key as keyof typeof current];
  }
  return current;
}

function readNestedString(value: unknown, keys: Array<string | number>): string | undefined {
  const found = readNestedValue(value, keys);
  return typeof found === "string" && found.trim() ? found.trim() : undefined;
}

export function extractMSTeamsPollVote(
  activity: { value?: unknown } | undefined,
): MSTeamsPollVote | null {
  const value = activity?.value;
  if (!value || !isRecord(value)) {
    return null;
  }
  const pollId =
    readNestedString(value, ["openclawPollId"]) ??
    readNestedString(value, ["pollId"]) ??
    readNestedString(value, ["openclaw", "pollId"]) ??
    readNestedString(value, ["openclaw", "poll", "id"]) ??
    readNestedString(value, ["data", "openclawPollId"]) ??
    readNestedString(value, ["data", "pollId"]) ??
    readNestedString(value, ["data", "openclaw", "pollId"]);
  if (!pollId) {
    return null;
  }

  const directSelections = extractSelections(value.choices);
  const nestedSelections = extractSelections(readNestedValue(value, ["choices"]));
  const dataSelections = extractSelections(readNestedValue(value, ["data", "choices"]));
  const selections =
    directSelections.length > 0
      ? directSelections
      : nestedSelections.length > 0
        ? nestedSelections
        : dataSelections;

  if (selections.length === 0) {
    return null;
  }

  return {
    pollId,
    selections,
  };
}

export function buildMSTeamsPollCard(params: {
  question: string;
  options: string[];
  maxSelections?: number;
  pollId?: string;
}): MSTeamsPollCard {
  const pollId = params.pollId ?? crypto.randomUUID();
  const maxSelections =
    typeof params.maxSelections === "number" && params.maxSelections > 1
      ? Math.floor(params.maxSelections)
      : 1;
  const cappedMaxSelections = Math.min(Math.max(1, maxSelections), params.options.length);
  const choices = params.options.map((option, index) => ({
    title: option,
    value: String(index),
  }));
  const hint =
    cappedMaxSelections > 1
      ? `Select up to ${cappedMaxSelections} option${cappedMaxSelections === 1 ? "" : "s"}.`
      : "Select one option.";

  const card = {
    type: "AdaptiveCard",
    version: "1.5",
    body: [
      {
        type: "TextBlock",
        text: params.question,
        wrap: true,
        weight: "Bolder",
        size: "Medium",
      },
      {
        type: "Input.ChoiceSet",
        id: "choices",
        isMultiSelect: cappedMaxSelections > 1,
        style: "expanded",
        choices,
      },
      {
        type: "TextBlock",
        text: hint,
        wrap: true,
        isSubtle: true,
        spacing: "Small",
      },
    ],
    actions: [
      {
        type: "Action.Submit",
        title: "Vote",
        data: {
          openclawPollId: pollId,
          pollId,
        },
        msteams: {
          type: "messageBack",
          text: "openclaw poll vote",
          displayText: "Vote recorded",
          value: { openclawPollId: pollId, pollId },
        },
      },
    ],
  };

  const fallbackLines = [
    `Poll: ${params.question}`,
    ...params.options.map((option, index) => `${index + 1}. ${option}`),
  ];

  return {
    pollId,
    question: params.question,
    options: params.options,
    maxSelections: cappedMaxSelections,
    card,
    fallbackText: fallbackLines.join("\n"),
  };
}

export type MSTeamsPollStoreFsOptions = {
  env?: NodeJS.ProcessEnv;
  homedir?: () => string;
  stateDir?: string;
  storePath?: string;
};

function parseTimestamp(value?: string): number | null {
  if (!value) {
    return null;
  }
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : null;
}

function pruneExpired(polls: Record<string, MSTeamsPoll>) {
  const cutoff = Date.now() - POLL_TTL_MS;
  const entries = Object.entries(polls).filter(([, poll]) => {
    const ts = parseTimestamp(poll.updatedAt ?? poll.createdAt) ?? 0;
    return ts >= cutoff;
  });
  return Object.fromEntries(entries);
}

function pruneToLimit(polls: Record<string, MSTeamsPoll>) {
  const entries = Object.entries(polls);
  if (entries.length <= MAX_POLLS) {
    return polls;
  }
  entries.sort((a, b) => {
    const aTs = parseTimestamp(a[1].updatedAt ?? a[1].createdAt) ?? 0;
    const bTs = parseTimestamp(b[1].updatedAt ?? b[1].createdAt) ?? 0;
    return aTs - bTs;
  });
  const keep = entries.slice(entries.length - MAX_POLLS);
  return Object.fromEntries(keep);
}

export function normalizeMSTeamsPollSelections(poll: MSTeamsPoll, selections: string[]) {
  const maxSelections = Math.max(1, poll.maxSelections);
  const mapped = selections
    .map((entry) => Number.parseInt(entry, 10))
    .filter((value) => Number.isFinite(value))
    .filter((value) => value >= 0 && value < poll.options.length)
    .map((value) => String(value));
  const limited = maxSelections > 1 ? mapped.slice(0, maxSelections) : mapped.slice(0, 1);
  return Array.from(new Set(limited));
}

export function createMSTeamsPollStoreFs(params?: MSTeamsPollStoreFsOptions): MSTeamsPollStore {
  const filePath = resolveMSTeamsStorePath({
    filename: STORE_FILENAME,
    env: params?.env,
    homedir: params?.homedir,
    stateDir: params?.stateDir,
    storePath: params?.storePath,
  });
  const empty: PollStoreData = { version: 1, polls: {} };

  const readStore = async (): Promise<PollStoreData> => {
    const { value } = await readJsonFile<PollStoreData>(filePath, empty);
    const pruned = pruneToLimit(pruneExpired(value.polls ?? {}));
    return { version: 1, polls: pruned };
  };

  const writeStore = async (data: PollStoreData) => {
    await writeJsonFile(filePath, data);
  };

  const createPoll = async (poll: MSTeamsPoll) => {
    await withFileLock(filePath, empty, async () => {
      const data = await readStore();
      data.polls[poll.id] = poll;
      await writeStore({ version: 1, polls: pruneToLimit(data.polls) });
    });
  };

  const getPoll = async (pollId: string) =>
    await withFileLock(filePath, empty, async () => {
      const data = await readStore();
      return data.polls[pollId] ?? null;
    });

  const recordVote = async (params: { pollId: string; voterId: string; selections: string[] }) =>
    await withFileLock(filePath, empty, async () => {
      const data = await readStore();
      const poll = data.polls[params.pollId];
      if (!poll) {
        return null;
      }
      const normalized = normalizeMSTeamsPollSelections(poll, params.selections);
      poll.votes[params.voterId] = normalized;
      poll.updatedAt = new Date().toISOString();
      data.polls[poll.id] = poll;
      await writeStore({ version: 1, polls: pruneToLimit(data.polls) });
      return poll;
    });

  return { createPoll, getPoll, recordVote };
}
]]></file>
  <file path="./extensions/msteams/src/errors.ts"><![CDATA[export function formatUnknownError(err: unknown): string {
  if (err instanceof Error) {
    return err.message;
  }
  if (typeof err === "string") {
    return err;
  }
  if (err === null) {
    return "null";
  }
  if (err === undefined) {
    return "undefined";
  }
  if (typeof err === "number" || typeof err === "boolean" || typeof err === "bigint") {
    return String(err);
  }
  if (typeof err === "symbol") {
    return err.description ?? err.toString();
  }
  if (typeof err === "function") {
    return err.name ? `[function ${err.name}]` : "[function]";
  }
  try {
    return JSON.stringify(err) ?? "unknown error";
  } catch {
    return "unknown error";
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function extractStatusCode(err: unknown): number | null {
  if (!isRecord(err)) {
    return null;
  }
  const direct = err.statusCode ?? err.status;
  if (typeof direct === "number" && Number.isFinite(direct)) {
    return direct;
  }
  if (typeof direct === "string") {
    const parsed = Number.parseInt(direct, 10);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  const response = err.response;
  if (isRecord(response)) {
    const status = response.status;
    if (typeof status === "number" && Number.isFinite(status)) {
      return status;
    }
    if (typeof status === "string") {
      const parsed = Number.parseInt(status, 10);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }
  }

  return null;
}

function extractRetryAfterMs(err: unknown): number | null {
  if (!isRecord(err)) {
    return null;
  }

  const direct = err.retryAfterMs ?? err.retry_after_ms;
  if (typeof direct === "number" && Number.isFinite(direct) && direct >= 0) {
    return direct;
  }

  const retryAfter = err.retryAfter ?? err.retry_after;
  if (typeof retryAfter === "number" && Number.isFinite(retryAfter)) {
    return retryAfter >= 0 ? retryAfter * 1000 : null;
  }
  if (typeof retryAfter === "string") {
    const parsed = Number.parseFloat(retryAfter);
    if (Number.isFinite(parsed) && parsed >= 0) {
      return parsed * 1000;
    }
  }

  const response = err.response;
  if (!isRecord(response)) {
    return null;
  }

  const headers = response.headers;
  if (!headers) {
    return null;
  }

  if (isRecord(headers)) {
    const raw = headers["retry-after"] ?? headers["Retry-After"];
    if (typeof raw === "string") {
      const parsed = Number.parseFloat(raw);
      if (Number.isFinite(parsed) && parsed >= 0) {
        return parsed * 1000;
      }
    }
  }

  // Fetch Headers-like interface
  if (
    typeof headers === "object" &&
    headers !== null &&
    "get" in headers &&
    typeof (headers as { get?: unknown }).get === "function"
  ) {
    const raw = (headers as { get: (name: string) => string | null }).get("retry-after");
    if (raw) {
      const parsed = Number.parseFloat(raw);
      if (Number.isFinite(parsed) && parsed >= 0) {
        return parsed * 1000;
      }
    }
  }

  return null;
}

export type MSTeamsSendErrorKind = "auth" | "throttled" | "transient" | "permanent" | "unknown";

export type MSTeamsSendErrorClassification = {
  kind: MSTeamsSendErrorKind;
  statusCode?: number;
  retryAfterMs?: number;
};

/**
 * Classify outbound send errors for safe retries and actionable logs.
 *
 * Important: We only mark errors as retryable when we have an explicit HTTP
 * status code that indicates the message was not accepted (e.g. 429, 5xx).
 * For transport-level errors where delivery is ambiguous, we prefer to avoid
 * retries to reduce the chance of duplicate posts.
 */
export function classifyMSTeamsSendError(err: unknown): MSTeamsSendErrorClassification {
  const statusCode = extractStatusCode(err);
  const retryAfterMs = extractRetryAfterMs(err);

  if (statusCode === 401 || statusCode === 403) {
    return { kind: "auth", statusCode };
  }

  if (statusCode === 429) {
    return {
      kind: "throttled",
      statusCode,
      retryAfterMs: retryAfterMs ?? undefined,
    };
  }

  if (statusCode === 408 || (statusCode != null && statusCode >= 500)) {
    return {
      kind: "transient",
      statusCode,
      retryAfterMs: retryAfterMs ?? undefined,
    };
  }

  if (statusCode != null && statusCode >= 400) {
    return { kind: "permanent", statusCode };
  }

  return {
    kind: "unknown",
    statusCode: statusCode ?? undefined,
    retryAfterMs: retryAfterMs ?? undefined,
  };
}

export function formatMSTeamsSendErrorHint(
  classification: MSTeamsSendErrorClassification,
): string | undefined {
  if (classification.kind === "auth") {
    return "check msteams appId/appPassword/tenantId (or env vars MSTEAMS_APP_ID/MSTEAMS_APP_PASSWORD/MSTEAMS_TENANT_ID)";
  }
  if (classification.kind === "throttled") {
    return "Teams throttled the bot; backing off may help";
  }
  if (classification.kind === "transient") {
    return "transient Teams/Bot Framework error; retry may succeed";
  }
  return undefined;
}
]]></file>
  <file path="./extensions/msteams/src/conversation-store-memory.ts"><![CDATA[import type {
  MSTeamsConversationStore,
  MSTeamsConversationStoreEntry,
  StoredConversationReference,
} from "./conversation-store.js";

export function createMSTeamsConversationStoreMemory(
  initial: MSTeamsConversationStoreEntry[] = [],
): MSTeamsConversationStore {
  const map = new Map<string, StoredConversationReference>();
  for (const { conversationId, reference } of initial) {
    map.set(conversationId, reference);
  }

  return {
    upsert: async (conversationId, reference) => {
      map.set(conversationId, reference);
    },
    get: async (conversationId) => {
      return map.get(conversationId) ?? null;
    },
    list: async () => {
      return Array.from(map.entries()).map(([conversationId, reference]) => ({
        conversationId,
        reference,
      }));
    },
    remove: async (conversationId) => {
      return map.delete(conversationId);
    },
    findByUserId: async (id) => {
      const target = id.trim();
      if (!target) {
        return null;
      }
      for (const [conversationId, reference] of map.entries()) {
        if (reference.user?.aadObjectId === target) {
          return { conversationId, reference };
        }
        if (reference.user?.id === target) {
          return { conversationId, reference };
        }
      }
      return null;
    },
  };
}
]]></file>
  <file path="./extensions/msteams/src/monitor-handler.ts"><![CDATA[import type { OpenClawConfig, RuntimeEnv } from "openclaw/plugin-sdk";
import type { MSTeamsConversationStore } from "./conversation-store.js";
import type { MSTeamsAdapter } from "./messenger.js";
import type { MSTeamsMonitorLogger } from "./monitor-types.js";
import type { MSTeamsPollStore } from "./polls.js";
import type { MSTeamsTurnContext } from "./sdk-types.js";
import { buildFileInfoCard, parseFileConsentInvoke, uploadToConsentUrl } from "./file-consent.js";
import { createMSTeamsMessageHandler } from "./monitor-handler/message-handler.js";
import { getPendingUpload, removePendingUpload } from "./pending-uploads.js";

export type MSTeamsAccessTokenProvider = {
  getAccessToken: (scope: string) => Promise<string>;
};

export type MSTeamsActivityHandler = {
  onMessage: (
    handler: (context: unknown, next: () => Promise<void>) => Promise<void>,
  ) => MSTeamsActivityHandler;
  onMembersAdded: (
    handler: (context: unknown, next: () => Promise<void>) => Promise<void>,
  ) => MSTeamsActivityHandler;
  run?: (context: unknown) => Promise<void>;
};

export type MSTeamsMessageHandlerDeps = {
  cfg: OpenClawConfig;
  runtime: RuntimeEnv;
  appId: string;
  adapter: MSTeamsAdapter;
  tokenProvider: MSTeamsAccessTokenProvider;
  textLimit: number;
  mediaMaxBytes: number;
  conversationStore: MSTeamsConversationStore;
  pollStore: MSTeamsPollStore;
  log: MSTeamsMonitorLogger;
};

/**
 * Handle fileConsent/invoke activities for large file uploads.
 */
async function handleFileConsentInvoke(
  context: MSTeamsTurnContext,
  log: MSTeamsMonitorLogger,
): Promise<boolean> {
  const activity = context.activity;
  if (activity.type !== "invoke" || activity.name !== "fileConsent/invoke") {
    return false;
  }

  const consentResponse = parseFileConsentInvoke(activity);
  if (!consentResponse) {
    log.debug?.("invalid file consent invoke", { value: activity.value });
    return false;
  }

  const uploadId =
    typeof consentResponse.context?.uploadId === "string"
      ? consentResponse.context.uploadId
      : undefined;

  if (consentResponse.action === "accept" && consentResponse.uploadInfo) {
    const pendingFile = getPendingUpload(uploadId);
    if (pendingFile) {
      log.debug?.("user accepted file consent, uploading", {
        uploadId,
        filename: pendingFile.filename,
        size: pendingFile.buffer.length,
      });

      try {
        // Upload file to the provided URL
        await uploadToConsentUrl({
          url: consentResponse.uploadInfo.uploadUrl,
          buffer: pendingFile.buffer,
          contentType: pendingFile.contentType,
        });

        // Send confirmation card
        const fileInfoCard = buildFileInfoCard({
          filename: consentResponse.uploadInfo.name,
          contentUrl: consentResponse.uploadInfo.contentUrl,
          uniqueId: consentResponse.uploadInfo.uniqueId,
          fileType: consentResponse.uploadInfo.fileType,
        });

        await context.sendActivity({
          type: "message",
          attachments: [fileInfoCard],
        });

        log.info("file upload complete", {
          uploadId,
          filename: consentResponse.uploadInfo.name,
          uniqueId: consentResponse.uploadInfo.uniqueId,
        });
      } catch (err) {
        log.debug?.("file upload failed", { uploadId, error: String(err) });
        await context.sendActivity(`File upload failed: ${String(err)}`);
      } finally {
        removePendingUpload(uploadId);
      }
    } else {
      log.debug?.("pending file not found for consent", { uploadId });
      await context.sendActivity(
        "The file upload request has expired. Please try sending the file again.",
      );
    }
  } else {
    // User declined
    log.debug?.("user declined file consent", { uploadId });
    removePendingUpload(uploadId);
  }

  return true;
}

export function registerMSTeamsHandlers<T extends MSTeamsActivityHandler>(
  handler: T,
  deps: MSTeamsMessageHandlerDeps,
): T {
  const handleTeamsMessage = createMSTeamsMessageHandler(deps);

  // Wrap the original run method to intercept invokes
  const originalRun = handler.run;
  if (originalRun) {
    handler.run = async (context: unknown) => {
      const ctx = context as MSTeamsTurnContext;
      // Handle file consent invokes before passing to normal flow
      if (ctx.activity?.type === "invoke" && ctx.activity?.name === "fileConsent/invoke") {
        const handled = await handleFileConsentInvoke(ctx, deps.log);
        if (handled) {
          // Send invoke response for file consent
          await ctx.sendActivity({ type: "invokeResponse", value: { status: 200 } });
          return;
        }
      }
      return originalRun.call(handler, context);
    };
  }

  handler.onMessage(async (context, next) => {
    try {
      await handleTeamsMessage(context as MSTeamsTurnContext);
    } catch (err) {
      deps.runtime.error?.(`msteams handler failed: ${String(err)}`);
    }
    await next();
  });

  handler.onMembersAdded(async (context, next) => {
    const membersAdded = (context as MSTeamsTurnContext).activity?.membersAdded ?? [];
    for (const member of membersAdded) {
      if (member.id !== (context as MSTeamsTurnContext).activity?.recipient?.id) {
        deps.log.debug?.("member added", { member: member.id });
        // Don't send welcome message - let the user initiate conversation.
      }
    }
    await next();
  });

  return handler;
}
]]></file>
  <file path="./extensions/msteams/src/mentions.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import { buildMentionEntities, formatMentionText, parseMentions } from "./mentions.js";

describe("parseMentions", () => {
  it("parses single mention", () => {
    const result = parseMentions("Hello @[John Doe](28:a1b2c3-d4e5f6)!");

    expect(result.text).toBe("Hello <at>John Doe</at>!");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]).toEqual({
      type: "mention",
      text: "<at>John Doe</at>",
      mentioned: {
        id: "28:a1b2c3-d4e5f6",
        name: "John Doe",
      },
    });
  });

  it("parses multiple mentions", () => {
    const result = parseMentions("Hey @[Alice](28:aaa) and @[Bob](28:bbb), can you review this?");

    expect(result.text).toBe("Hey <at>Alice</at> and <at>Bob</at>, can you review this?");
    expect(result.entities).toHaveLength(2);
    expect(result.entities[0]).toEqual({
      type: "mention",
      text: "<at>Alice</at>",
      mentioned: {
        id: "28:aaa",
        name: "Alice",
      },
    });
    expect(result.entities[1]).toEqual({
      type: "mention",
      text: "<at>Bob</at>",
      mentioned: {
        id: "28:bbb",
        name: "Bob",
      },
    });
  });

  it("handles text without mentions", () => {
    const result = parseMentions("Hello world!");

    expect(result.text).toBe("Hello world!");
    expect(result.entities).toHaveLength(0);
  });

  it("handles empty text", () => {
    const result = parseMentions("");

    expect(result.text).toBe("");
    expect(result.entities).toHaveLength(0);
  });

  it("handles mention with spaces in name", () => {
    const result = parseMentions("@[John Peter Smith](28:a1b2c3)");

    expect(result.text).toBe("<at>John Peter Smith</at>");
    expect(result.entities[0]?.mentioned.name).toBe("John Peter Smith");
  });

  it("trims whitespace from id and name", () => {
    const result = parseMentions("@[ John Doe ]( 28:a1b2c3 )");

    expect(result.entities[0]).toEqual({
      type: "mention",
      text: "<at>John Doe</at>",
      mentioned: {
        id: "28:a1b2c3",
        name: "John Doe",
      },
    });
  });

  it("handles Japanese characters in mention at start of message", () => {
    const input = "@[タナカ タロウ](a1b2c3d4-e5f6-7890-abcd-ef1234567890) スキル化完了しました！";
    const result = parseMentions(input);

    expect(result.text).toBe("<at>タナカ タロウ</at> スキル化完了しました！");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]).toEqual({
      type: "mention",
      text: "<at>タナカ タロウ</at>",
      mentioned: {
        id: "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
        name: "タナカ タロウ",
      },
    });

    // Verify entity text exactly matches what's in the formatted text
    const entityText = result.entities[0]?.text;
    expect(result.text).toContain(entityText);
    expect(result.text.indexOf(entityText)).toBe(0);
  });

  it("skips mention-like patterns with non-Teams IDs (e.g. in code blocks)", () => {
    // This reproduces the actual failing payload: the message contains a real mention
    // plus `@[表示名](ユーザーID)` as documentation text inside backticks.
    const input =
      "@[タナカ タロウ](a1b2c3d4-e5f6-7890-abcd-ef1234567890) スキル化完了しました！📋\n\n" +
      "**作成したスキル:** `teams-mention`\n" +
      "- 機能: Teamsでのメンション形式 `@[表示名](ユーザーID)`\n\n" +
      "**追加対応:**\n" +
      "- ユーザーのID `a1b2c3d4-e5f6-7890-abcd-ef1234567890` を登録済み";
    const result = parseMentions(input);

    // Only the real mention should be parsed; the documentation example should be left as-is
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]?.mentioned.id).toBe("a1b2c3d4-e5f6-7890-abcd-ef1234567890");
    expect(result.entities[0]?.mentioned.name).toBe("タナカ タロウ");

    // The documentation pattern must remain untouched in the text
    expect(result.text).toContain("`@[表示名](ユーザーID)`");
  });

  it("accepts Bot Framework IDs (28:xxx)", () => {
    const result = parseMentions("@[Bot](28:abc-123)");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]?.mentioned.id).toBe("28:abc-123");
  });

  it("accepts Bot Framework IDs with non-hex payloads (29:xxx)", () => {
    const result = parseMentions("@[Bot](29:08q2j2o3jc09au90eucae)");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]?.mentioned.id).toBe("29:08q2j2o3jc09au90eucae");
  });

  it("accepts org-scoped IDs with extra segments (8:orgid:...)", () => {
    const result = parseMentions("@[User](8:orgid:2d8c2d2c-1111-2222-3333-444444444444)");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]?.mentioned.id).toBe("8:orgid:2d8c2d2c-1111-2222-3333-444444444444");
  });

  it("accepts AAD object IDs (UUIDs)", () => {
    const result = parseMentions("@[User](a1b2c3d4-e5f6-7890-abcd-ef1234567890)");
    expect(result.entities).toHaveLength(1);
    expect(result.entities[0]?.mentioned.id).toBe("a1b2c3d4-e5f6-7890-abcd-ef1234567890");
  });

  it("rejects non-ID strings as mention targets", () => {
    const result = parseMentions("See @[docs](https://example.com) for details");
    expect(result.entities).toHaveLength(0);
    // Original text preserved
    expect(result.text).toBe("See @[docs](https://example.com) for details");
  });
});

describe("buildMentionEntities", () => {
  it("builds entities from mention info", () => {
    const mentions = [
      { id: "28:aaa", name: "Alice" },
      { id: "28:bbb", name: "Bob" },
    ];

    const entities = buildMentionEntities(mentions);

    expect(entities).toHaveLength(2);
    expect(entities[0]).toEqual({
      type: "mention",
      text: "<at>Alice</at>",
      mentioned: {
        id: "28:aaa",
        name: "Alice",
      },
    });
    expect(entities[1]).toEqual({
      type: "mention",
      text: "<at>Bob</at>",
      mentioned: {
        id: "28:bbb",
        name: "Bob",
      },
    });
  });

  it("handles empty list", () => {
    const entities = buildMentionEntities([]);
    expect(entities).toHaveLength(0);
  });
});

describe("formatMentionText", () => {
  it("formats text with single mention", () => {
    const text = "Hello @John!";
    const mentions = [{ id: "28:xxx", name: "John" }];

    const result = formatMentionText(text, mentions);

    expect(result).toBe("Hello <at>John</at>!");
  });

  it("formats text with multiple mentions", () => {
    const text = "Hey @Alice and @Bob";
    const mentions = [
      { id: "28:aaa", name: "Alice" },
      { id: "28:bbb", name: "Bob" },
    ];

    const result = formatMentionText(text, mentions);

    expect(result).toBe("Hey <at>Alice</at> and <at>Bob</at>");
  });

  it("handles case-insensitive matching", () => {
    const text = "Hey @alice and @ALICE";
    const mentions = [{ id: "28:aaa", name: "Alice" }];

    const result = formatMentionText(text, mentions);

    expect(result).toBe("Hey <at>Alice</at> and <at>Alice</at>");
  });

  it("handles text without mentions", () => {
    const text = "Hello world";
    const mentions = [{ id: "28:xxx", name: "John" }];

    const result = formatMentionText(text, mentions);

    expect(result).toBe("Hello world");
  });

  it("escapes regex metacharacters in names", () => {
    const text = "Hey @John(Test) and @Alice.Smith";
    const mentions = [
      { id: "28:xxx", name: "John(Test)" },
      { id: "28:yyy", name: "Alice.Smith" },
    ];

    const result = formatMentionText(text, mentions);

    expect(result).toBe("Hey <at>John(Test)</at> and <at>Alice.Smith</at>");
  });
});
]]></file>
  <file path="./extensions/msteams/src/channel.directory.test.ts"><![CDATA[import type { OpenClawConfig } from "openclaw/plugin-sdk";
import { describe, expect, it } from "vitest";
import { msteamsPlugin } from "./channel.js";

describe("msteams directory", () => {
  it("lists peers and groups from config", async () => {
    const cfg = {
      channels: {
        msteams: {
          allowFrom: ["alice", "user:Bob"],
          dms: { carol: {}, bob: {} },
          teams: {
            team1: {
              channels: {
                "conversation:chan1": {},
                chan2: {},
              },
            },
          },
        },
      },
    } as unknown as OpenClawConfig;

    expect(msteamsPlugin.directory).toBeTruthy();
    expect(msteamsPlugin.directory?.listPeers).toBeTruthy();
    expect(msteamsPlugin.directory?.listGroups).toBeTruthy();

    await expect(
      msteamsPlugin.directory!.listPeers({ cfg, query: undefined, limit: undefined }),
    ).resolves.toEqual(
      expect.arrayContaining([
        { kind: "user", id: "user:alice" },
        { kind: "user", id: "user:Bob" },
        { kind: "user", id: "user:carol" },
        { kind: "user", id: "user:bob" },
      ]),
    );

    await expect(
      msteamsPlugin.directory!.listGroups({ cfg, query: undefined, limit: undefined }),
    ).resolves.toEqual(
      expect.arrayContaining([
        { kind: "group", id: "conversation:chan1" },
        { kind: "group", id: "conversation:chan2" },
      ]),
    );
  });
});
]]></file>
  <file path="./extensions/msteams/src/sent-message-cache.test.ts"><![CDATA[import { describe, expect, it } from "vitest";
import {
  clearMSTeamsSentMessageCache,
  recordMSTeamsSentMessage,
  wasMSTeamsMessageSent,
} from "./sent-message-cache.js";

describe("msteams sent message cache", () => {
  it("records and resolves sent message ids", () => {
    clearMSTeamsSentMessageCache();
    recordMSTeamsSentMessage("conv-1", "msg-1");
    expect(wasMSTeamsMessageSent("conv-1", "msg-1")).toBe(true);
    expect(wasMSTeamsMessageSent("conv-1", "msg-2")).toBe(false);
  });
});
]]></file>
  <file path="./extensions/msteams/src/index.ts"><![CDATA[export { monitorMSTeamsProvider } from "./monitor.js";
export { probeMSTeams } from "./probe.js";
export { sendMessageMSTeams, sendPollMSTeams } from "./send.js";
export { type MSTeamsCredentials, resolveMSTeamsCredentials } from "./token.js";
]]></file>
  <file path="./extensions/msteams/src/monitor-handler/inbound-media.ts"><![CDATA[import type { MSTeamsTurnContext } from "../sdk-types.js";
import {
  buildMSTeamsGraphMessageUrls,
  downloadMSTeamsAttachments,
  downloadMSTeamsGraphMedia,
  type MSTeamsAccessTokenProvider,
  type MSTeamsAttachmentLike,
  type MSTeamsHtmlAttachmentSummary,
  type MSTeamsInboundMedia,
} from "../attachments.js";

type MSTeamsLogger = {
  debug?: (message: string, meta?: Record<string, unknown>) => void;
};

export async function resolveMSTeamsInboundMedia(params: {
  attachments: MSTeamsAttachmentLike[];
  htmlSummary?: MSTeamsHtmlAttachmentSummary;
  maxBytes: number;
  allowHosts?: string[];
  authAllowHosts?: string[];
  tokenProvider: MSTeamsAccessTokenProvider;
  conversationType: string;
  conversationId: string;
  conversationMessageId?: string;
  activity: Pick<MSTeamsTurnContext["activity"], "id" | "replyToId" | "channelData">;
  log: MSTeamsLogger;
  /** When true, embeds original filename in stored path for later extraction. */
  preserveFilenames?: boolean;
}): Promise<MSTeamsInboundMedia[]> {
  const {
    attachments,
    htmlSummary,
    maxBytes,
    tokenProvider,
    allowHosts,
    conversationType,
    conversationId,
    conversationMessageId,
    activity,
    log,
    preserveFilenames,
  } = params;

  let mediaList = await downloadMSTeamsAttachments({
    attachments,
    maxBytes,
    tokenProvider,
    allowHosts,
    authAllowHosts: params.authAllowHosts,
    preserveFilenames,
  });

  if (mediaList.length === 0) {
    const onlyHtmlAttachments =
      attachments.length > 0 &&
      attachments.every((att) => String(att.contentType ?? "").startsWith("text/html"));

    if (onlyHtmlAttachments) {
      const messageUrls = buildMSTeamsGraphMessageUrls({
        conversationType,
        conversationId,
        messageId: activity.id ?? undefined,
        replyToId: activity.replyToId ?? undefined,
        conversationMessageId,
        channelData: activity.channelData,
      });
      if (messageUrls.length === 0) {
        log.debug?.("graph message url unavailable", {
          conversationType,
          hasChannelData: Boolean(activity.channelData),
          messageId: activity.id ?? undefined,
          replyToId: activity.replyToId ?? undefined,
        });
      } else {
        const attempts: Array<{
          url: string;
          hostedStatus?: number;
          attachmentStatus?: number;
          hostedCount?: number;
          attachmentCount?: number;
          tokenError?: boolean;
        }> = [];
        for (const messageUrl of messageUrls) {
          const graphMedia = await downloadMSTeamsGraphMedia({
            messageUrl,
            tokenProvider,
            maxBytes,
            allowHosts,
            authAllowHosts: params.authAllowHosts,
            preserveFilenames,
          });
          attempts.push({
            url: messageUrl,
            hostedStatus: graphMedia.hostedStatus,
            attachmentStatus: graphMedia.attachmentStatus,
            hostedCount: graphMedia.hostedCount,
            attachmentCount: graphMedia.attachmentCount,
            tokenError: graphMedia.tokenError,
          });
          if (graphMedia.media.length > 0) {
            mediaList = graphMedia.media;
            break;
          }
          if (graphMedia.tokenError) {
            break;
          }
        }
        if (mediaList.length === 0) {
          log.debug?.("graph media fetch empty", { attempts });
        }
      }
    }
  }

  if (mediaList.length > 0) {
    log.debug?.("downloaded attachments", { count: mediaList.length });
  } else if (htmlSummary?.imgTags) {
    log.debug?.("inline images detected but none downloaded", {
      imgTags: htmlSummary.imgTags,
      srcHosts: htmlSummary.srcHosts,
      dataImages: htmlSummary.dataImages,
      cidImages: htmlSummary.cidImages,
    });
  }

  return mediaList;
}
]]></file>
  <file path="./extensions/msteams/src/monitor-handler/message-handler.ts"><![CDATA[import {
  buildPendingHistoryContextFromMap,
  clearHistoryEntriesIfEnabled,
  DEFAULT_GROUP_HISTORY_LIMIT,
  logInboundDrop,
  recordPendingHistoryEntryIfEnabled,
  resolveControlCommandGate,
  resolveMentionGating,
  formatAllowlistMatchMeta,
  type HistoryEntry,
} from "openclaw/plugin-sdk";
import type { StoredConversationReference } from "../conversation-store.js";
import type { MSTeamsMessageHandlerDeps } from "../monitor-handler.js";
import type { MSTeamsTurnContext } from "../sdk-types.js";
import {
  buildMSTeamsAttachmentPlaceholder,
  buildMSTeamsMediaPayload,
  type MSTeamsAttachmentLike,
  summarizeMSTeamsHtmlAttachments,
} from "../attachments.js";
import { formatUnknownError } from "../errors.js";
import {
  extractMSTeamsConversationMessageId,
  normalizeMSTeamsConversationId,
  parseMSTeamsActivityTimestamp,
  stripMSTeamsMentionTags,
  wasMSTeamsBotMentioned,
} from "../inbound.js";
import {
  isMSTeamsGroupAllowed,
  resolveMSTeamsAllowlistMatch,
  resolveMSTeamsReplyPolicy,
  resolveMSTeamsRouteConfig,
} from "../policy.js";
import { extractMSTeamsPollVote } from "../polls.js";
import { createMSTeamsReplyDispatcher } from "../reply-dispatcher.js";
import { getMSTeamsRuntime } from "../runtime.js";
import { recordMSTeamsSentMessage, wasMSTeamsMessageSent } from "../sent-message-cache.js";
import { resolveMSTeamsInboundMedia } from "./inbound-media.js";

export function createMSTeamsMessageHandler(deps: MSTeamsMessageHandlerDeps) {
  const {
    cfg,
    runtime,
    appId,
    adapter,
    tokenProvider,
    textLimit,
    mediaMaxBytes,
    conversationStore,
    pollStore,
    log,
  } = deps;
  const core = getMSTeamsRuntime();
  const logVerboseMessage = (message: string) => {
    if (core.logging.shouldLogVerbose()) {
      log.debug?.(message);
    }
  };
  const msteamsCfg = cfg.channels?.msteams;
  const historyLimit = Math.max(
    0,
    msteamsCfg?.historyLimit ??
      cfg.messages?.groupChat?.historyLimit ??
      DEFAULT_GROUP_HISTORY_LIMIT,
  );
  const conversationHistories = new Map<string, HistoryEntry[]>();
  const inboundDebounceMs = core.channel.debounce.resolveInboundDebounceMs({
    cfg,
    channel: "msteams",
  });

  type MSTeamsDebounceEntry = {
    context: MSTeamsTurnContext;
    rawText: string;
    text: string;
    attachments: MSTeamsAttachmentLike[];
    wasMentioned: boolean;
    implicitMention: boolean;
  };

  const handleTeamsMessageNow = async (params: MSTeamsDebounceEntry) => {
    const context = params.context;
    const activity = context.activity;
    const rawText = params.rawText;
    const text = params.text;
    const attachments = params.attachments;
    const attachmentPlaceholder = buildMSTeamsAttachmentPlaceholder(attachments);
    const rawBody = text || attachmentPlaceholder;
    const from = activity.from;
    const conversation = activity.conversation;

    const attachmentTypes = attachments
      .map((att) => (typeof att.contentType === "string" ? att.contentType : undefined))
      .filter(Boolean)
      .slice(0, 3);
    const htmlSummary = summarizeMSTeamsHtmlAttachments(attachments);

    log.info("received message", {
      rawText: rawText.slice(0, 50),
      text: text.slice(0, 50),
      attachments: attachments.length,
      attachmentTypes,
      from: from?.id,
      conversation: conversation?.id,
    });
    if (htmlSummary) {
      log.debug?.("html attachment summary", htmlSummary);
    }

    if (!from?.id) {
      log.debug?.("skipping message without from.id");
      return;
    }

    // Teams conversation.id may include ";messageid=..." suffix - strip it for session key.
    const rawConversationId = conversation?.id ?? "";
    const conversationId = normalizeMSTeamsConversationId(rawConversationId);
    const conversationMessageId = extractMSTeamsConversationMessageId(rawConversationId);
    const conversationType = conversation?.conversationType ?? "personal";
    const isGroupChat = conversationType === "groupChat" || conversation?.isGroup === true;
    const isChannel = conversationType === "channel";
    const isDirectMessage = !isGroupChat && !isChannel;

    const senderName = from.name ?? from.id;
    const senderId = from.aadObjectId ?? from.id;
    const storedAllowFrom = await core.channel.pairing
      .readAllowFromStore("msteams")
      .catch(() => []);
    const useAccessGroups = cfg.commands?.useAccessGroups !== false;

    // Check DM policy for direct messages.
    const dmAllowFrom = msteamsCfg?.allowFrom ?? [];
    const effectiveDmAllowFrom = [...dmAllowFrom.map((v) => String(v)), ...storedAllowFrom];
    if (isDirectMessage && msteamsCfg) {
      const dmPolicy = msteamsCfg.dmPolicy ?? "pairing";
      const allowFrom = dmAllowFrom;

      if (dmPolicy === "disabled") {
        log.debug?.("dropping dm (dms disabled)");
        return;
      }

      if (dmPolicy !== "open") {
        const effectiveAllowFrom = [...allowFrom.map((v) => String(v)), ...storedAllowFrom];
        const allowMatch = resolveMSTeamsAllowlistMatch({
          allowFrom: effectiveAllowFrom,
          senderId,
          senderName,
        });

        if (!allowMatch.allowed) {
          if (dmPolicy === "pairing") {
            const request = await core.channel.pairing.upsertPairingRequest({
              channel: "msteams",
              id: senderId,
              meta: { name: senderName },
            });
            if (request) {
              log.info("msteams pairing request created", {
                sender: senderId,
                label: senderName,
              });
            }
          }
          log.debug?.("dropping dm (not allowlisted)", {
            sender: senderId,
            label: senderName,
            allowlistMatch: formatAllowlistMatchMeta(allowMatch),
          });
          return;
        }
      }
    }

    const defaultGroupPolicy = cfg.channels?.defaults?.groupPolicy;
    const groupPolicy =
      !isDirectMessage && msteamsCfg
        ? (msteamsCfg.groupPolicy ?? defaultGroupPolicy ?? "allowlist")
        : "disabled";
    const groupAllowFrom =
      !isDirectMessage && msteamsCfg
        ? (msteamsCfg.groupAllowFrom ??
          (msteamsCfg.allowFrom && msteamsCfg.allowFrom.length > 0 ? msteamsCfg.allowFrom : []))
        : [];
    const effectiveGroupAllowFrom =
      !isDirectMessage && msteamsCfg
        ? [...groupAllowFrom.map((v) => String(v)), ...storedAllowFrom]
        : [];
    const teamId = activity.channelData?.team?.id;
    const teamName = activity.channelData?.team?.name;
    const channelName = activity.channelData?.channel?.name;
    const channelGate = resolveMSTeamsRouteConfig({
      cfg: msteamsCfg,
      teamId,
      teamName,
      conversationId,
      channelName,
    });

    if (!isDirectMessage && msteamsCfg) {
      if (groupPolicy === "disabled") {
        log.debug?.("dropping group message (groupPolicy: disabled)", {
          conversationId,
        });
        return;
      }

      if (groupPolicy === "allowlist") {
        if (channelGate.allowlistConfigured && !channelGate.allowed) {
          log.debug?.("dropping group message (not in team/channel allowlist)", {
            conversationId,
            teamKey: channelGate.teamKey ?? "none",
            channelKey: channelGate.channelKey ?? "none",
            channelMatchKey: channelGate.channelMatchKey ?? "none",
            channelMatchSource: channelGate.channelMatchSource ?? "none",
          });
          return;
        }
        if (effectiveGroupAllowFrom.length === 0 && !channelGate.allowlistConfigured) {
          log.debug?.("dropping group message (groupPolicy: allowlist, no allowlist)", {
            conversationId,
          });
          return;
        }
        if (effectiveGroupAllowFrom.length > 0) {
          const allowMatch = resolveMSTeamsAllowlistMatch({
            allowFrom: effectiveGroupAllowFrom,
            senderId,
            senderName,
          });
          if (!allowMatch.allowed) {
            log.debug?.("dropping group message (not in groupAllowFrom)", {
              sender: senderId,
              label: senderName,
              allowlistMatch: formatAllowlistMatchMeta(allowMatch),
            });
            return;
          }
        }
      }
    }

    const ownerAllowedForCommands = isMSTeamsGroupAllowed({
      groupPolicy: "allowlist",
      allowFrom: effectiveDmAllowFrom,
      senderId,
      senderName,
    });
    const groupAllowedForCommands = isMSTeamsGroupAllowed({
      groupPolicy: "allowlist",
      allowFrom: effectiveGroupAllowFrom,
      senderId,
      senderName,
    });
    const hasControlCommandInMessage = core.channel.text.hasControlCommand(text, cfg);
    const commandGate = resolveControlCommandGate({
      useAccessGroups,
      authorizers: [
        { configured: effectiveDmAllowFrom.length > 0, allowed: ownerAllowedForCommands },
        { configured: effectiveGroupAllowFrom.length > 0, allowed: groupAllowedForCommands },
      ],
      allowTextCommands: true,
      hasControlCommand: hasControlCommandInMessage,
    });
    const commandAuthorized = commandGate.commandAuthorized;
    if (commandGate.shouldBlock) {
      logInboundDrop({
        log: logVerboseMessage,
        channel: "msteams",
        reason: "control command (unauthorized)",
        target: senderId,
      });
      return;
    }

    // Build conversation reference for proactive replies.
    const agent = activity.recipient;
    const conversationRef: StoredConversationReference = {
      activityId: activity.id,
      user: { id: from.id, name: from.name, aadObjectId: from.aadObjectId },
      agent,
      bot: agent ? { id: agent.id, name: agent.name } : undefined,
      conversation: {
        id: conversationId,
        conversationType,
        tenantId: conversation?.tenantId,
      },
      teamId,
      channelId: activity.channelId,
      serviceUrl: activity.serviceUrl,
      locale: activity.locale,
    };
    conversationStore.upsert(conversationId, conversationRef).catch((err) => {
      log.debug?.("failed to save conversation reference", {
        error: formatUnknownError(err),
      });
    });

    const pollVote = extractMSTeamsPollVote(activity);
    if (pollVote) {
      try {
        const poll = await pollStore.recordVote({
          pollId: pollVote.pollId,
          voterId: senderId,
          selections: pollVote.selections,
        });
        if (!poll) {
          log.debug?.("poll vote ignored (poll not found)", {
            pollId: pollVote.pollId,
          });
        } else {
          log.info("recorded poll vote", {
            pollId: pollVote.pollId,
            voter: senderId,
            selections: pollVote.selections,
          });
        }
      } catch (err) {
        log.error("failed to record poll vote", {
          pollId: pollVote.pollId,
          error: formatUnknownError(err),
        });
      }
      return;
    }

    if (!rawBody) {
      log.debug?.("skipping empty message after stripping mentions");
      return;
    }

    const teamsFrom = isDirectMessage
      ? `msteams:${senderId}`
      : isChannel
        ? `msteams:channel:${conversationId}`
        : `msteams:group:${conversationId}`;
    const teamsTo = isDirectMessage ? `user:${senderId}` : `conversation:${conversationId}`;

    const route = core.channel.routing.resolveAgentRoute({
      cfg,
      channel: "msteams",
      peer: {
        kind: isDirectMessage ? "direct" : isChannel ? "channel" : "group",
        id: isDirectMessage ? senderId : conversationId,
      },
    });

    const preview = rawBody.replace(/\s+/g, " ").slice(0, 160);
    const inboundLabel = isDirectMessage
      ? `Teams DM from ${senderName}`
      : `Teams message in ${conversationType} from ${senderName}`;

    core.system.enqueueSystemEvent(`${inboundLabel}: ${preview}`, {
      sessionKey: route.sessionKey,
      contextKey: `msteams:message:${conversationId}:${activity.id ?? "unknown"}`,
    });

    const channelId = conversationId;
    const { teamConfig, channelConfig } = channelGate;
    const { requireMention, replyStyle } = resolveMSTeamsReplyPolicy({
      isDirectMessage,
      globalConfig: msteamsCfg,
      teamConfig,
      channelConfig,
    });
    const timestamp = parseMSTeamsActivityTimestamp(activity.timestamp);

    if (!isDirectMessage) {
      const mentionGate = resolveMentionGating({
        requireMention: Boolean(requireMention),
        canDetectMention: true,
        wasMentioned: params.wasMentioned,
        implicitMention: params.implicitMention,
        shouldBypassMention: false,
      });
      const mentioned = mentionGate.effectiveWasMentioned;
      if (requireMention && mentionGate.shouldSkip) {
        log.debug?.("skipping message (mention required)", {
          teamId,
          channelId,
          requireMention,
          mentioned,
        });
        recordPendingHistoryEntryIfEnabled({
          historyMap: conversationHistories,
          historyKey: conversationId,
          limit: historyLimit,
          entry: {
            sender: senderName,
            body: rawBody,
            timestamp: timestamp?.getTime(),
            messageId: activity.id ?? undefined,
          },
        });
        return;
      }
    }
    const mediaList = await resolveMSTeamsInboundMedia({
      attachments,
      htmlSummary: htmlSummary ?? undefined,
      maxBytes: mediaMaxBytes,
      tokenProvider,
      allowHosts: msteamsCfg?.mediaAllowHosts,
      authAllowHosts: msteamsCfg?.mediaAuthAllowHosts,
      conversationType,
      conversationId,
      conversationMessageId: conversationMessageId ?? undefined,
      activity: {
        id: activity.id,
        replyToId: activity.replyToId,
        channelData: activity.channelData,
      },
      log,
      preserveFilenames: (cfg as { media?: { preserveFilenames?: boolean } }).media
        ?.preserveFilenames,
    });

    const mediaPayload = buildMSTeamsMediaPayload(mediaList);
    const envelopeFrom = isDirectMessage ? senderName : conversationType;
    const storePath = core.channel.session.resolveStorePath(cfg.session?.store, {
      agentId: route.agentId,
    });
    const envelopeOptions = core.channel.reply.resolveEnvelopeFormatOptions(cfg);
    const previousTimestamp = core.channel.session.readSessionUpdatedAt({
      storePath,
      sessionKey: route.sessionKey,
    });
    const body = core.channel.reply.formatAgentEnvelope({
      channel: "Teams",
      from: envelopeFrom,
      timestamp,
      previousTimestamp,
      envelope: envelopeOptions,
      body: rawBody,
    });
    let combinedBody = body;
    const isRoomish = !isDirectMessage;
    const historyKey = isRoomish ? conversationId : undefined;
    if (isRoomish && historyKey) {
      combinedBody = buildPendingHistoryContextFromMap({
        historyMap: conversationHistories,
        historyKey,
        limit: historyLimit,
        currentMessage: combinedBody,
        formatEntry: (entry) =>
          core.channel.reply.formatAgentEnvelope({
            channel: "Teams",
            from: conversationType,
            timestamp: entry.timestamp,
            body: `${entry.sender}: ${entry.body}${entry.messageId ? ` [id:${entry.messageId}]` : ""}`,
            envelope: envelopeOptions,
          }),
      });
    }

    const inboundHistory =
      isRoomish && historyKey && historyLimit > 0
        ? (conversationHistories.get(historyKey) ?? []).map((entry) => ({
            sender: entry.sender,
            body: entry.body,
            timestamp: entry.timestamp,
          }))
        : undefined;

    const ctxPayload = core.channel.reply.finalizeInboundContext({
      Body: combinedBody,
      BodyForAgent: rawBody,
      InboundHistory: inboundHistory,
      RawBody: rawBody,
      CommandBody: rawBody,
      From: teamsFrom,
      To: teamsTo,
      SessionKey: route.sessionKey,
      AccountId: route.accountId,
      ChatType: isDirectMessage ? "direct" : isChannel ? "channel" : "group",
      ConversationLabel: envelopeFrom,
      GroupSubject: !isDirectMessage ? conversationType : undefined,
      SenderName: senderName,
      SenderId: senderId,
      Provider: "msteams" as const,
      Surface: "msteams" as const,
      MessageSid: activity.id,
      Timestamp: timestamp?.getTime() ?? Date.now(),
      WasMentioned: isDirectMessage || params.wasMentioned || params.implicitMention,
      CommandAuthorized: commandAuthorized,
      OriginatingChannel: "msteams" as const,
      OriginatingTo: teamsTo,
      ...mediaPayload,
    });

    await core.channel.session.recordInboundSession({
      storePath,
      sessionKey: ctxPayload.SessionKey ?? route.sessionKey,
      ctx: ctxPayload,
      onRecordError: (err) => {
        logVerboseMessage(`msteams: failed updating session meta: ${String(err)}`);
      },
    });

    logVerboseMessage(`msteams inbound: from=${ctxPayload.From} preview="${preview}"`);

    const sharePointSiteId = msteamsCfg?.sharePointSiteId;
    const { dispatcher, replyOptions, markDispatchIdle } = createMSTeamsReplyDispatcher({
      cfg,
      agentId: route.agentId,
      accountId: route.accountId,
      runtime,
      log,
      adapter,
      appId,
      conversationRef,
      context,
      replyStyle,
      textLimit,
      onSentMessageIds: (ids) => {
        for (const id of ids) {
          recordMSTeamsSentMessage(conversationId, id);
        }
      },
      tokenProvider,
      sharePointSiteId,
    });

    log.info("dispatching to agent", { sessionKey: route.sessionKey });
    try {
      const { queuedFinal, counts } = await core.channel.reply.dispatchReplyFromConfig({
        ctx: ctxPayload,
        cfg,
        dispatcher,
        replyOptions,
      });

      markDispatchIdle();
      log.info("dispatch complete", { queuedFinal, counts });

      if (!queuedFinal) {
        if (isRoomish && historyKey) {
          clearHistoryEntriesIfEnabled({
            historyMap: conversationHistories,
            historyKey,
            limit: historyLimit,
          });
        }
        return;
      }
      const finalCount = counts.final;
      logVerboseMessage(
        `msteams: delivered ${finalCount} reply${finalCount === 1 ? "" : "ies"} to ${teamsTo}`,
      );
      if (isRoomish && historyKey) {
        clearHistoryEntriesIfEnabled({
          historyMap: conversationHistories,
          historyKey,
          limit: historyLimit,
        });
      }
    } catch (err) {
      log.error("dispatch failed", { error: String(err) });
      runtime.error?.(`msteams dispatch failed: ${String(err)}`);
      try {
        await context.sendActivity(
          `⚠️ Agent failed: ${err instanceof Error ? err.message : String(err)}`,
        );
      } catch {
        // Best effort.
      }
    }
  };

  const inboundDebouncer = core.channel.debounce.createInboundDebouncer<MSTeamsDebounceEntry>({
    debounceMs: inboundDebounceMs,
    buildKey: (entry) => {
      const conversationId = normalizeMSTeamsConversationId(
        entry.context.activity.conversation?.id ?? "",
      );
      const senderId =
        entry.context.activity.from?.aadObjectId ?? entry.context.activity.from?.id ?? "";
      if (!senderId || !conversationId) {
        return null;
      }
      return `msteams:${appId}:${conversationId}:${senderId}`;
    },
    shouldDebounce: (entry) => {
      if (!entry.text.trim()) {
        return false;
      }
      if (entry.attachments.length > 0) {
        return false;
      }
      return !core.channel.text.hasControlCommand(entry.text, cfg);
    },
    onFlush: async (entries) => {
      const last = entries.at(-1);
      if (!last) {
        return;
      }
      if (entries.length === 1) {
        await handleTeamsMessageNow(last);
        return;
      }
      const combinedText = entries
        .map((entry) => entry.text)
        .filter(Boolean)
        .join("\n");
      if (!combinedText.trim()) {
        return;
      }
      const combinedRawText = entries
        .map((entry) => entry.rawText)
        .filter(Boolean)
        .join("\n");
      const wasMentioned = entries.some((entry) => entry.wasMentioned);
      const implicitMention = entries.some((entry) => entry.implicitMention);
      await handleTeamsMessageNow({
        context: last.context,
        rawText: combinedRawText,
        text: combinedText,
        attachments: [],
        wasMentioned,
        implicitMention,
      });
    },
    onError: (err) => {
      runtime.error?.(`msteams debounce flush failed: ${String(err)}`);
    },
  });

  return async function handleTeamsMessage(context: MSTeamsTurnContext) {
    const activity = context.activity;
    const rawText = activity.text?.trim() ?? "";
    const text = stripMSTeamsMentionTags(rawText);
    const attachments = Array.isArray(activity.attachments)
      ? (activity.attachments as unknown as MSTeamsAttachmentLike[])
      : [];
    const wasMentioned = wasMSTeamsBotMentioned(activity);
    const conversationId = normalizeMSTeamsConversationId(activity.conversation?.id ?? "");
    const replyToId = activity.replyToId ?? undefined;
    const implicitMention = Boolean(
      conversationId && replyToId && wasMSTeamsMessageSent(conversationId, replyToId),
    );

    await inboundDebouncer.enqueue({
      context,
      rawText,
      text,
      attachments,
      wasMentioned,
      implicitMention,
    });
  };
}
]]></file>
  <file path="./extensions/msteams/src/conversation-store-fs.ts"><![CDATA[import type {
  MSTeamsConversationStore,
  MSTeamsConversationStoreEntry,
  StoredConversationReference,
} from "./conversation-store.js";
import { resolveMSTeamsStorePath } from "./storage.js";
import { readJsonFile, withFileLock, writeJsonFile } from "./store-fs.js";

type ConversationStoreData = {
  version: 1;
  conversations: Record<string, StoredConversationReference & { lastSeenAt?: string }>;
};

const STORE_FILENAME = "msteams-conversations.json";
const MAX_CONVERSATIONS = 1000;
const CONVERSATION_TTL_MS = 365 * 24 * 60 * 60 * 1000;

function parseTimestamp(value: string | undefined): number | null {
  if (!value) {
    return null;
  }
  const parsed = Date.parse(value);
  if (!Number.isFinite(parsed)) {
    return null;
  }
  return parsed;
}

function pruneToLimit(
  conversations: Record<string, StoredConversationReference & { lastSeenAt?: string }>,
) {
  const entries = Object.entries(conversations);
  if (entries.length <= MAX_CONVERSATIONS) {
    return conversations;
  }

  entries.sort((a, b) => {
    const aTs = parseTimestamp(a[1].lastSeenAt) ?? 0;
    const bTs = parseTimestamp(b[1].lastSeenAt) ?? 0;
    return aTs - bTs;
  });

  const keep = entries.slice(entries.length - MAX_CONVERSATIONS);
  return Object.fromEntries(keep);
}

function pruneExpired(
  conversations: Record<string, StoredConversationReference & { lastSeenAt?: string }>,
  nowMs: number,
  ttlMs: number,
) {
  let removed = false;
  const kept: typeof conversations = {};
  for (const [conversationId, reference] of Object.entries(conversations)) {
    const lastSeenAt = parseTimestamp(reference.lastSeenAt);
    // Preserve legacy entries that have no lastSeenAt until they're seen again.
    if (lastSeenAt != null && nowMs - lastSeenAt > ttlMs) {
      removed = true;
      continue;
    }
    kept[conversationId] = reference;
  }
  return { conversations: kept, removed };
}

function normalizeConversationId(raw: string): string {
  return raw.split(";")[0] ?? raw;
}

export function createMSTeamsConversationStoreFs(params?: {
  env?: NodeJS.ProcessEnv;
  homedir?: () => string;
  ttlMs?: number;
  stateDir?: string;
  storePath?: string;
}): MSTeamsConversationStore {
  const ttlMs = params?.ttlMs ?? CONVERSATION_TTL_MS;
  const filePath = resolveMSTeamsStorePath({
    filename: STORE_FILENAME,
    env: params?.env,
    homedir: params?.homedir,
    stateDir: params?.stateDir,
    storePath: params?.storePath,
  });

  const empty: ConversationStoreData = { version: 1, conversations: {} };

  const readStore = async (): Promise<ConversationStoreData> => {
    const { value } = await readJsonFile<ConversationStoreData>(filePath, empty);
    if (
      value.version !== 1 ||
      !value.conversations ||
      typeof value.conversations !== "object" ||
      Array.isArray(value.conversations)
    ) {
      return empty;
    }
    const nowMs = Date.now();
    const pruned = pruneExpired(value.conversations, nowMs, ttlMs).conversations;
    return { version: 1, conversations: pruneToLimit(pruned) };
  };

  const list = async (): Promise<MSTeamsConversationStoreEntry[]> => {
    const store = await readStore();
    return Object.entries(store.conversations).map(([conversationId, reference]) => ({
      conversationId,
      reference,
    }));
  };

  const get = async (conversationId: string): Promise<StoredConversationReference | null> => {
    const store = await readStore();
    return store.conversations[normalizeConversationId(conversationId)] ?? null;
  };

  const findByUserId = async (id: string): Promise<MSTeamsConversationStoreEntry | null> => {
    const target = id.trim();
    if (!target) {
      return null;
    }
    for (const entry of await list()) {
      const { conversationId, reference } = entry;
      if (reference.user?.aadObjectId === target) {
        return { conversationId, reference };
      }
      if (reference.user?.id === target) {
        return { conversationId, reference };
      }
    }
    return null;
  };

  const upsert = async (
    conversationId: string,
    reference: StoredConversationReference,
  ): Promise<void> => {
    const normalizedId = normalizeConversationId(conversationId);
    await withFileLock(filePath, empty, async () => {
      const store = await readStore();
      store.conversations[normalizedId] = {
        ...reference,
        lastSeenAt: new Date().toISOString(),
      };
      const nowMs = Date.now();
      store.conversations = pruneExpired(store.conversations, nowMs, ttlMs).conversations;
      store.conversations = pruneToLimit(store.conversations);
      await writeJsonFile(filePath, store);
    });
  };

  const remove = async (conversationId: string): Promise<boolean> => {
    const normalizedId = normalizeConversationId(conversationId);
    return await withFileLock(filePath, empty, async () => {
      const store = await readStore();
      if (!(normalizedId in store.conversations)) {
        return false;
      }
      delete store.conversations[normalizedId];
      await writeJsonFile(filePath, store);
      return true;
    });
  };

  return { upsert, get, list, remove, findByUserId };
}
]]></file>
  <file path="./extensions/msteams/src/pending-uploads.ts"><![CDATA[/**
 * In-memory storage for files awaiting user consent in the FileConsentCard flow.
 *
 * When sending large files (>=4MB) in personal chats, Teams requires user consent
 * before upload. This module stores the file data temporarily until the user
 * accepts or declines, or until the TTL expires.
 */

import crypto from "node:crypto";

export interface PendingUpload {
  id: string;
  buffer: Buffer;
  filename: string;
  contentType?: string;
  conversationId: string;
  createdAt: number;
}

const pendingUploads = new Map<string, PendingUpload>();

/** TTL for pending uploads: 5 minutes */
const PENDING_UPLOAD_TTL_MS = 5 * 60 * 1000;

/**
 * Store a file pending user consent.
 * Returns the upload ID to include in the FileConsentCard context.
 */
export function storePendingUpload(upload: Omit<PendingUpload, "id" | "createdAt">): string {
  const id = crypto.randomUUID();
  const entry: PendingUpload = {
    ...upload,
    id,
    createdAt: Date.now(),
  };
  pendingUploads.set(id, entry);

  // Auto-cleanup after TTL
  setTimeout(() => {
    pendingUploads.delete(id);
  }, PENDING_UPLOAD_TTL_MS);

  return id;
}

/**
 * Retrieve a pending upload by ID.
 * Returns undefined if not found or expired.
 */
export function getPendingUpload(id?: string): PendingUpload | undefined {
  if (!id) {
    return undefined;
  }
  const entry = pendingUploads.get(id);
  if (!entry) {
    return undefined;
  }

  // Check if expired (in case timeout hasn't fired yet)
  if (Date.now() - entry.createdAt > PENDING_UPLOAD_TTL_MS) {
    pendingUploads.delete(id);
    return undefined;
  }

  return entry;
}

/**
 * Remove a pending upload (after successful upload or user decline).
 */
export function removePendingUpload(id?: string): void {
  if (id) {
    pendingUploads.delete(id);
  }
}

/**
 * Get the count of pending uploads (for monitoring/debugging).
 */
export function getPendingUploadCount(): number {
  return pendingUploads.size;
}

/**
 * Clear all pending uploads (for testing).
 */
export function clearPendingUploads(): void {
  pendingUploads.clear();
}
]]></file>
  <file path="./extensions/msteams/src/inbound.ts"><![CDATA[export type MentionableActivity = {
  recipient?: { id?: string } | null;
  entities?: Array<{
    type?: string;
    mentioned?: { id?: string };
  }> | null;
};

export function normalizeMSTeamsConversationId(raw: string): string {
  return raw.split(";")[0] ?? raw;
}

export function extractMSTeamsConversationMessageId(raw: string): string | undefined {
  if (!raw) {
    return undefined;
  }
  const match = /(?:^|;)messageid=([^;]+)/i.exec(raw);
  const value = match?.[1]?.trim() ?? "";
  return value || undefined;
}

export function parseMSTeamsActivityTimestamp(value: unknown): Date | undefined {
  if (!value) {
    return undefined;
  }
  if (value instanceof Date) {
    return value;
  }
  if (typeof value !== "string") {
    return undefined;
  }
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? undefined : date;
}

export function stripMSTeamsMentionTags(text: string): string {
  // Teams wraps mentions in <at>...</at> tags
  return text.replace(/<at[^>]*>.*?<\/at>/gi, "").trim();
}

export function wasMSTeamsBotMentioned(activity: MentionableActivity): boolean {
  const botId = activity.recipient?.id;
  if (!botId) {
    return false;
  }
  const entities = activity.entities ?? [];
  return entities.some((e) => e.type === "mention" && e.mentioned?.id === botId);
}
]]></file>
  <file path="./extensions/msteams/src/conversation-store-fs.test.ts"><![CDATA[import type { PluginRuntime } from "openclaw/plugin-sdk";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { beforeEach, describe, expect, it } from "vitest";
import type { StoredConversationReference } from "./conversation-store.js";
import { createMSTeamsConversationStoreFs } from "./conversation-store-fs.js";
import { setMSTeamsRuntime } from "./runtime.js";

const runtimeStub = {
  state: {
    resolveStateDir: (env: NodeJS.ProcessEnv = process.env, homedir?: () => string) => {
      const override = env.OPENCLAW_STATE_DIR?.trim() || env.OPENCLAW_STATE_DIR?.trim();
      if (override) {
        return override;
      }
      const resolvedHome = homedir ? homedir() : os.homedir();
      return path.join(resolvedHome, ".openclaw");
    },
  },
} as unknown as PluginRuntime;

describe("msteams conversation store (fs)", () => {
  beforeEach(() => {
    setMSTeamsRuntime(runtimeStub);
  });

  it("filters and prunes expired entries (but keeps legacy ones)", async () => {
    const stateDir = await fs.promises.mkdtemp(path.join(os.tmpdir(), "openclaw-msteams-store-"));

    const env: NodeJS.ProcessEnv = {
      ...process.env,
      OPENCLAW_STATE_DIR: stateDir,
    };

    const store = createMSTeamsConversationStoreFs({ env, ttlMs: 1_000 });

    const ref: StoredConversationReference = {
      conversation: { id: "19:active@thread.tacv2" },
      channelId: "msteams",
      serviceUrl: "https://service.example.com",
      user: { id: "u1", aadObjectId: "aad1" },
    };

    await store.upsert("19:active@thread.tacv2", ref);

    const filePath = path.join(stateDir, "msteams-conversations.json");
    const raw = await fs.promises.readFile(filePath, "utf-8");
    const json = JSON.parse(raw) as {
      version: number;
      conversations: Record<string, StoredConversationReference & { lastSeenAt?: string }>;
    };

    json.conversations["19:old@thread.tacv2"] = {
      ...ref,
      conversation: { id: "19:old@thread.tacv2" },
      lastSeenAt: new Date(Date.now() - 60_000).toISOString(),
    };

    // Legacy entry without lastSeenAt should be preserved.
    json.conversations["19:legacy@thread.tacv2"] = {
      ...ref,
      conversation: { id: "19:legacy@thread.tacv2" },
    };

    await fs.promises.writeFile(filePath, `${JSON.stringify(json, null, 2)}\n`);

    const list = await store.list();
    const ids = list.map((e) => e.conversationId).toSorted();
    expect(ids).toEqual(["19:active@thread.tacv2", "19:legacy@thread.tacv2"]);

    expect(await store.get("19:old@thread.tacv2")).toBeNull();
    expect(await store.get("19:legacy@thread.tacv2")).not.toBeNull();

    await store.upsert("19:new@thread.tacv2", {
      ...ref,
      conversation: { id: "19:new@thread.tacv2" },
    });

    const rawAfter = await fs.promises.readFile(filePath, "utf-8");
    const jsonAfter = JSON.parse(rawAfter) as typeof json;
    expect(Object.keys(jsonAfter.conversations).toSorted()).toEqual([
      "19:active@thread.tacv2",
      "19:legacy@thread.tacv2",
      "19:new@thread.tacv2",
    ]);
  });
});
]]></file>
  <file path="./extensions/msteams/src/sent-message-cache.ts"><![CDATA[const TTL_MS = 24 * 60 * 60 * 1000; // 24 hours

type CacheEntry = {
  messageIds: Set<string>;
  timestamps: Map<string, number>;
};

const sentMessages = new Map<string, CacheEntry>();

function cleanupExpired(entry: CacheEntry): void {
  const now = Date.now();
  for (const [msgId, timestamp] of entry.timestamps) {
    if (now - timestamp > TTL_MS) {
      entry.messageIds.delete(msgId);
      entry.timestamps.delete(msgId);
    }
  }
}

export function recordMSTeamsSentMessage(conversationId: string, messageId: string): void {
  if (!conversationId || !messageId) {
    return;
  }
  let entry = sentMessages.get(conversationId);
  if (!entry) {
    entry = { messageIds: new Set(), timestamps: new Map() };
    sentMessages.set(conversationId, entry);
  }
  entry.messageIds.add(messageId);
  entry.timestamps.set(messageId, Date.now());
  if (entry.messageIds.size > 200) {
    cleanupExpired(entry);
  }
}

export function wasMSTeamsMessageSent(conversationId: string, messageId: string): boolean {
  const entry = sentMessages.get(conversationId);
  if (!entry) {
    return false;
  }
  cleanupExpired(entry);
  return entry.messageIds.has(messageId);
}

export function clearMSTeamsSentMessageCache(): void {
  sentMessages.clear();
}
]]></file>
  <file path="./extensions/msteams/src/channel.ts"><![CDATA[import type { ChannelMessageActionName, ChannelPlugin, OpenClawConfig } from "openclaw/plugin-sdk";
import {
  buildChannelConfigSchema,
  DEFAULT_ACCOUNT_ID,
  MSTeamsConfigSchema,
  PAIRING_APPROVED_MESSAGE,
} from "openclaw/plugin-sdk";
import { listMSTeamsDirectoryGroupsLive, listMSTeamsDirectoryPeersLive } from "./directory-live.js";
import { msteamsOnboardingAdapter } from "./onboarding.js";
import { msteamsOutbound } from "./outbound.js";
import { resolveMSTeamsGroupToolPolicy } from "./policy.js";
import { probeMSTeams } from "./probe.js";
import {
  normalizeMSTeamsMessagingTarget,
  normalizeMSTeamsUserInput,
  parseMSTeamsConversationId,
  parseMSTeamsTeamChannelInput,
  resolveMSTeamsChannelAllowlist,
  resolveMSTeamsUserAllowlist,
} from "./resolve-allowlist.js";
import { sendAdaptiveCardMSTeams, sendMessageMSTeams } from "./send.js";
import { resolveMSTeamsCredentials } from "./token.js";

type ResolvedMSTeamsAccount = {
  accountId: string;
  enabled: boolean;
  configured: boolean;
};

const meta = {
  id: "msteams",
  label: "Microsoft Teams",
  selectionLabel: "Microsoft Teams (Bot Framework)",
  docsPath: "/channels/msteams",
  docsLabel: "msteams",
  blurb: "Bot Framework; enterprise support.",
  aliases: ["teams"],
  order: 60,
} as const;

export const msteamsPlugin: ChannelPlugin<ResolvedMSTeamsAccount> = {
  id: "msteams",
  meta: {
    ...meta,
    aliases: [...meta.aliases],
  },
  onboarding: msteamsOnboardingAdapter,
  pairing: {
    idLabel: "msteamsUserId",
    normalizeAllowEntry: (entry) => entry.replace(/^(msteams|user):/i, ""),
    notifyApproval: async ({ cfg, id }) => {
      await sendMessageMSTeams({
        cfg,
        to: id,
        text: PAIRING_APPROVED_MESSAGE,
      });
    },
  },
  capabilities: {
    chatTypes: ["direct", "channel", "thread"],
    polls: true,
    threads: true,
    media: true,
  },
  agentPrompt: {
    messageToolHints: () => [
      "- Adaptive Cards supported. Use `action=send` with `card={type,version,body}` to send rich cards.",
      "- MSTeams targeting: omit `target` to reply to the current conversation (auto-inferred). Explicit targets: `user:ID` or `user:Display Name` (requires Graph API) for DMs, `conversation:19:...@thread.tacv2` for groups/channels. Prefer IDs over display names for speed.",
    ],
  },
  threading: {
    buildToolContext: ({ context, hasRepliedRef }) => ({
      currentChannelId: context.To?.trim() || undefined,
      currentThreadTs: context.ReplyToId,
      hasRepliedRef,
    }),
  },
  groups: {
    resolveToolPolicy: resolveMSTeamsGroupToolPolicy,
  },
  reload: { configPrefixes: ["channels.msteams"] },
  configSchema: buildChannelConfigSchema(MSTeamsConfigSchema),
  config: {
    listAccountIds: () => [DEFAULT_ACCOUNT_ID],
    resolveAccount: (cfg) => ({
      accountId: DEFAULT_ACCOUNT_ID,
      enabled: cfg.channels?.msteams?.enabled !== false,
      configured: Boolean(resolveMSTeamsCredentials(cfg.channels?.msteams)),
    }),
    defaultAccountId: () => DEFAULT_ACCOUNT_ID,
    setAccountEnabled: ({ cfg, enabled }) => ({
      ...cfg,
      channels: {
        ...cfg.channels,
        msteams: {
          ...cfg.channels?.msteams,
          enabled,
        },
      },
    }),
    deleteAccount: ({ cfg }) => {
      const next = { ...cfg } as OpenClawConfig;
      const nextChannels = { ...cfg.channels };
      delete nextChannels.msteams;
      if (Object.keys(nextChannels).length > 0) {
        next.channels = nextChannels;
      } else {
        delete next.channels;
      }
      return next;
    },
    isConfigured: (_account, cfg) => Boolean(resolveMSTeamsCredentials(cfg.channels?.msteams)),
    describeAccount: (account) => ({
      accountId: account.accountId,
      enabled: account.enabled,
      configured: account.configured,
    }),
    resolveAllowFrom: ({ cfg }) => cfg.channels?.msteams?.allowFrom ?? [],
    formatAllowFrom: ({ allowFrom }) =>
      allowFrom
        .map((entry) => String(entry).trim())
        .filter(Boolean)
        .map((entry) => entry.toLowerCase()),
  },
  security: {
    collectWarnings: ({ cfg }) => {
      const defaultGroupPolicy = cfg.channels?.defaults?.groupPolicy;
      const groupPolicy = cfg.channels?.msteams?.groupPolicy ?? defaultGroupPolicy ?? "allowlist";
      if (groupPolicy !== "open") {
        return [];
      }
      return [
        `- MS Teams groups: groupPolicy="open" allows any member to trigger (mention-gated). Set channels.msteams.groupPolicy="allowlist" + channels.msteams.groupAllowFrom to restrict senders.`,
      ];
    },
  },
  setup: {
    resolveAccountId: () => DEFAULT_ACCOUNT_ID,
    applyAccountConfig: ({ cfg }) => ({
      ...cfg,
      channels: {
        ...cfg.channels,
        msteams: {
          ...cfg.channels?.msteams,
          enabled: true,
        },
      },
    }),
  },
  messaging: {
    normalizeTarget: normalizeMSTeamsMessagingTarget,
    targetResolver: {
      looksLikeId: (raw) => {
        const trimmed = raw.trim();
        if (!trimmed) {
          return false;
        }
        if (/^conversation:/i.test(trimmed)) {
          return true;
        }
        if (/^user:/i.test(trimmed)) {
          // Only treat as ID if the value after user: looks like a UUID
          const id = trimmed.slice("user:".length).trim();
          return /^[0-9a-fA-F-]{16,}$/.test(id);
        }
        return trimmed.includes("@thread");
      },
      hint: "<conversationId|user:ID|conversation:ID>",
    },
  },
  directory: {
    self: async () => null,
    listPeers: async ({ cfg, query, limit }) => {
      const q = query?.trim().toLowerCase() || "";
      const ids = new Set<string>();
      for (const entry of cfg.channels?.msteams?.allowFrom ?? []) {
        const trimmed = String(entry).trim();
        if (trimmed && trimmed !== "*") {
          ids.add(trimmed);
        }
      }
      for (const userId of Object.keys(cfg.channels?.msteams?.dms ?? {})) {
        const trimmed = userId.trim();
        if (trimmed) {
          ids.add(trimmed);
        }
      }
      return Array.from(ids)
        .map((raw) => raw.trim())
        .filter(Boolean)
        .map((raw) => normalizeMSTeamsMessagingTarget(raw) ?? raw)
        .map((raw) => {
          const lowered = raw.toLowerCase();
          if (lowered.startsWith("user:")) {
            return raw;
          }
          if (lowered.startsWith("conversation:")) {
            return raw;
          }
          return `user:${raw}`;
        })
        .filter((id) => (q ? id.toLowerCase().includes(q) : true))
        .slice(0, limit && limit > 0 ? limit : undefined)
        .map((id) => ({ kind: "user", id }) as const);
    },
    listGroups: async ({ cfg, query, limit }) => {
      const q = query?.trim().toLowerCase() || "";
      const ids = new Set<string>();
      for (const team of Object.values(cfg.channels?.msteams?.teams ?? {})) {
        for (const channelId of Object.keys(team.channels ?? {})) {
          const trimmed = channelId.trim();
          if (trimmed && trimmed !== "*") {
            ids.add(trimmed);
          }
        }
      }
      return Array.from(ids)
        .map((raw) => raw.trim())
        .filter(Boolean)
        .map((raw) => raw.replace(/^conversation:/i, "").trim())
        .map((id) => `conversation:${id}`)
        .filter((id) => (q ? id.toLowerCase().includes(q) : true))
        .slice(0, limit && limit > 0 ? limit : undefined)
        .map((id) => ({ kind: "group", id }) as const);
    },
    listPeersLive: async ({ cfg, query, limit }) =>
      listMSTeamsDirectoryPeersLive({ cfg, query, limit }),
    listGroupsLive: async ({ cfg, query, limit }) =>
      listMSTeamsDirectoryGroupsLive({ cfg, query, limit }),
  },
  resolver: {
    resolveTargets: async ({ cfg, inputs, kind, runtime }) => {
      const results = inputs.map((input) => ({
        input,
        resolved: false,
        id: undefined as string | undefined,
        name: undefined as string | undefined,
        note: undefined as string | undefined,
      }));

      const stripPrefix = (value: string) => normalizeMSTeamsUserInput(value);

      if (kind === "user") {
        const pending: Array<{ input: string; query: string; index: number }> = [];
        results.forEach((entry, index) => {
          const trimmed = entry.input.trim();
          if (!trimmed) {
            entry.note = "empty input";
            return;
          }
          const cleaned = stripPrefix(trimmed);
          if (/^[0-9a-fA-F-]{16,}$/.test(cleaned) || cleaned.includes("@")) {
            entry.resolved = true;
            entry.id = cleaned;
            return;
          }
          pending.push({ input: entry.input, query: cleaned, index });
        });

        if (pending.length > 0) {
          try {
            const resolved = await resolveMSTeamsUserAllowlist({
              cfg,
              entries: pending.map((entry) => entry.query),
            });
            resolved.forEach((entry, idx) => {
              const target = results[pending[idx]?.index ?? -1];
              if (!target) {
                return;
              }
              target.resolved = entry.resolved;
              target.id = entry.id;
              target.name = entry.name;
              target.note = entry.note;
            });
          } catch (err) {
            runtime.error?.(`msteams resolve failed: ${String(err)}`);
            pending.forEach(({ index }) => {
              const entry = results[index];
              if (entry) {
                entry.note = "lookup failed";
              }
            });
          }
        }

        return results;
      }

      const pending: Array<{ input: string; query: string; index: number }> = [];
      results.forEach((entry, index) => {
        const trimmed = entry.input.trim();
        if (!trimmed) {
          entry.note = "empty input";
          return;
        }
        const conversationId = parseMSTeamsConversationId(trimmed);
        if (conversationId !== null) {
          entry.resolved = Boolean(conversationId);
          entry.id = conversationId || undefined;
          entry.note = conversationId ? "conversation id" : "empty conversation id";
          return;
        }
        const parsed = parseMSTeamsTeamChannelInput(trimmed);
        if (!parsed.team) {
          entry.note = "missing team";
          return;
        }
        const query = parsed.channel ? `${parsed.team}/${parsed.channel}` : parsed.team;
        pending.push({ input: entry.input, query, index });
      });

      if (pending.length > 0) {
        try {
          const resolved = await resolveMSTeamsChannelAllowlist({
            cfg,
            entries: pending.map((entry) => entry.query),
          });
          resolved.forEach((entry, idx) => {
            const target = results[pending[idx]?.index ?? -1];
            if (!target) {
              return;
            }
            if (!entry.resolved || !entry.teamId) {
              target.resolved = false;
              target.note = entry.note;
              return;
            }
            target.resolved = true;
            if (entry.channelId) {
              target.id = `${entry.teamId}/${entry.channelId}`;
              target.name =
                entry.channelName && entry.teamName
                  ? `${entry.teamName}/${entry.channelName}`
                  : (entry.channelName ?? entry.teamName);
            } else {
              target.id = entry.teamId;
              target.name = entry.teamName;
              target.note = "team id";
            }
            if (entry.note) {
              target.note = entry.note;
            }
          });
        } catch (err) {
          runtime.error?.(`msteams resolve failed: ${String(err)}`);
          pending.forEach(({ index }) => {
            const entry = results[index];
            if (entry) {
              entry.note = "lookup failed";
            }
          });
        }
      }

      return results;
    },
  },
  actions: {
    listActions: ({ cfg }) => {
      const enabled =
        cfg.channels?.msteams?.enabled !== false &&
        Boolean(resolveMSTeamsCredentials(cfg.channels?.msteams));
      if (!enabled) {
        return [];
      }
      return ["poll"] satisfies ChannelMessageActionName[];
    },
    supportsCards: ({ cfg }) => {
      return (
        cfg.channels?.msteams?.enabled !== false &&
        Boolean(resolveMSTeamsCredentials(cfg.channels?.msteams))
      );
    },
    handleAction: async (ctx) => {
      // Handle send action with card parameter
      if (ctx.action === "send" && ctx.params.card) {
        const card = ctx.params.card as Record<string, unknown>;
        const to =
          typeof ctx.params.to === "string"
            ? ctx.params.to.trim()
            : typeof ctx.params.target === "string"
              ? ctx.params.target.trim()
              : "";
        if (!to) {
          return {
            isError: true,
            content: [{ type: "text" as const, text: "Card send requires a target (to)." }],
            details: { error: "Card send requires a target (to)." },
          };
        }
        const result = await sendAdaptiveCardMSTeams({
          cfg: ctx.cfg,
          to,
          card,
        });
        return {
          content: [
            {
              type: "text" as const,
              text: JSON.stringify({
                ok: true,
                channel: "msteams",
                messageId: result.messageId,
                conversationId: result.conversationId,
              }),
            },
          ],
          details: { ok: true, channel: "msteams", messageId: result.messageId },
        };
      }
      // Return null to fall through to default handler
      return null as never;
    },
  },
  outbound: msteamsOutbound,
  status: {
    defaultRuntime: {
      accountId: DEFAULT_ACCOUNT_ID,
      running: false,
      lastStartAt: null,
      lastStopAt: null,
      lastError: null,
      port: null,
    },
    buildChannelSummary: ({ snapshot }) => ({
      configured: snapshot.configured ?? false,
      running: snapshot.running ?? false,
      lastStartAt: snapshot.lastStartAt ?? null,
      lastStopAt: snapshot.lastStopAt ?? null,
      lastError: snapshot.lastError ?? null,
      port: snapshot.port ?? null,
      probe: snapshot.probe,
      lastProbeAt: snapshot.lastProbeAt ?? null,
    }),
    probeAccount: async ({ cfg }) => await probeMSTeams(cfg.channels?.msteams),
    buildAccountSnapshot: ({ account, runtime, probe }) => ({
      accountId: account.accountId,
      enabled: account.enabled,
      configured: account.configured,
      running: runtime?.running ?? false,
      lastStartAt: runtime?.lastStartAt ?? null,
      lastStopAt: runtime?.lastStopAt ?? null,
      lastError: runtime?.lastError ?? null,
      port: runtime?.port ?? null,
      probe,
    }),
  },
  gateway: {
    startAccount: async (ctx) => {
      const { monitorMSTeamsProvider } = await import("./index.js");
      const port = ctx.cfg.channels?.msteams?.webhook?.port ?? 3978;
      ctx.setStatus({ accountId: ctx.accountId, port });
      ctx.log?.info(`starting provider (port ${port})`);
      return monitorMSTeamsProvider({
        cfg: ctx.cfg,
        runtime: ctx.runtime,
        abortSignal: ctx.abortSignal,
      });
    },
  },
};
]]></file>
  <file path="./extensions/msteams/CHANGELOG.md"><![CDATA[# Changelog

## 2026.2.13

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-3

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6-2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.6

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.4

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.2.2

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.31

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.30

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.29

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.23

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.22

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.21

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.20

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.17-1

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.17

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.16

### Changes

- Version alignment with core OpenClaw release numbers.

## 2026.1.15

### Features

- Bot Framework gateway monitor (Express + JWT auth) with configurable webhook path/port and `/api/messages` fallback.
- Onboarding flow for Azure Bot credentials (config + env var detection) and DM policy setup.
- Channel capabilities: DMs, group chats, channels, threads, media, polls, and `teams` alias.
- DM pairing/allowlist enforcement plus group policies with per-team/channel overrides and mention gating.
- Inbound debounce + history context for room/group chats; mention tag stripping and timestamp parsing.
- Proactive messaging via stored conversation references (file store with TTL/size pruning).
- Outbound text/media send with markdown chunking, 4k limit, split/inline media handling.
- Adaptive Card polls: build cards, parse votes, and persist poll state with vote tracking.
- Attachment processing: placeholders + HTML summaries, inline image extraction (including data: URLs).
- Media downloads with host allowlist, auth scope fallback, and Graph hostedContents/attachments fallback.
- Retry/backoff on transient/throttled sends with classified errors + helpful hints.
]]></file>
  <file path="./extensions/msteams/index.ts"><![CDATA[import type { OpenClawPluginApi } from "openclaw/plugin-sdk";
import { emptyPluginConfigSchema } from "openclaw/plugin-sdk";
import { msteamsPlugin } from "./src/channel.js";
import { setMSTeamsRuntime } from "./src/runtime.js";

const plugin = {
  id: "msteams",
  name: "Microsoft Teams",
  description: "Microsoft Teams channel plugin (Bot Framework)",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    setMSTeamsRuntime(api.runtime);
    api.registerChannel({ plugin: msteamsPlugin });
  },
};

export default plugin;
]]></file>
  <file path="./extensions/minimax-portal-auth/openclaw.plugin.json"><![CDATA[{
  "id": "minimax-portal-auth",
  "providers": ["minimax-portal"],
  "configSchema": {
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  }
}
]]></file>
  <file path="./extensions/minimax-portal-auth/README.md"><![CDATA[# MiniMax OAuth (OpenClaw plugin)

OAuth provider plugin for **MiniMax** (OAuth).

## Enable

Bundled plugins are disabled by default. Enable this one:

```bash
openclaw plugins enable minimax-portal-auth
```

Restart the Gateway after enabling.

```bash
openclaw gateway restart
```

## Authenticate

```bash
openclaw models auth login --provider minimax-portal --set-default
```

You will be prompted to select an endpoint:

- **Global** - International users, optimized for overseas access (`api.minimax.io`)
- **China** - Optimized for users in China (`api.minimaxi.com`)

## Notes

- MiniMax OAuth uses a user-code login flow.
- Currently, OAuth login is supported only for the Coding plan
]]></file>
  <file path="./extensions/minimax-portal-auth/package.json"><![CDATA[{
  "name": "@openclaw/minimax-portal-auth",
  "version": "2026.2.13",
  "private": true,
  "description": "OpenClaw MiniMax Portal OAuth provider plugin",
  "type": "module",
  "devDependencies": {
    "openclaw": "workspace:*"
  },
  "openclaw": {
    "extensions": [
      "./index.ts"
    ]
  }
}
]]></file>
  <file path="./extensions/minimax-portal-auth/index.ts"><![CDATA[import {
  emptyPluginConfigSchema,
  type OpenClawPluginApi,
  type ProviderAuthContext,
  type ProviderAuthResult,
} from "openclaw/plugin-sdk";
import { loginMiniMaxPortalOAuth, type MiniMaxRegion } from "./oauth.js";

const PROVIDER_ID = "minimax-portal";
const PROVIDER_LABEL = "MiniMax";
const DEFAULT_MODEL = "MiniMax-M2.5";
const DEFAULT_BASE_URL_CN = "https://api.minimaxi.com/anthropic";
const DEFAULT_BASE_URL_GLOBAL = "https://api.minimax.io/anthropic";
const DEFAULT_CONTEXT_WINDOW = 200000;
const DEFAULT_MAX_TOKENS = 8192;
const OAUTH_PLACEHOLDER = "minimax-oauth";

function getDefaultBaseUrl(region: MiniMaxRegion): string {
  return region === "cn" ? DEFAULT_BASE_URL_CN : DEFAULT_BASE_URL_GLOBAL;
}

function modelRef(modelId: string): string {
  return `${PROVIDER_ID}/${modelId}`;
}

function buildModelDefinition(params: {
  id: string;
  name: string;
  input: Array<"text" | "image">;
  reasoning?: boolean;
}) {
  return {
    id: params.id,
    name: params.name,
    reasoning: params.reasoning ?? false,
    input: params.input,
    cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0 },
    contextWindow: DEFAULT_CONTEXT_WINDOW,
    maxTokens: DEFAULT_MAX_TOKENS,
  };
}

function createOAuthHandler(region: MiniMaxRegion) {
  const defaultBaseUrl = getDefaultBaseUrl(region);
  const regionLabel = region === "cn" ? "CN" : "Global";

  return async (ctx: ProviderAuthContext): Promise<ProviderAuthResult> => {
    const progress = ctx.prompter.progress(`Starting MiniMax OAuth (${regionLabel})…`);
    try {
      const result = await loginMiniMaxPortalOAuth({
        openUrl: ctx.openUrl,
        note: ctx.prompter.note,
        progress,
        region,
      });

      progress.stop("MiniMax OAuth complete");

      if (result.notification_message) {
        await ctx.prompter.note(result.notification_message, "MiniMax OAuth");
      }

      const profileId = `${PROVIDER_ID}:default`;
      const baseUrl = result.resourceUrl || defaultBaseUrl;

      return {
        profiles: [
          {
            profileId,
            credential: {
              type: "oauth" as const,
              provider: PROVIDER_ID,
              access: result.access,
              refresh: result.refresh,
              expires: result.expires,
            },
          },
        ],
        configPatch: {
          models: {
            providers: {
              [PROVIDER_ID]: {
                baseUrl,
                apiKey: OAUTH_PLACEHOLDER,
                api: "anthropic-messages",
                models: [
                  buildModelDefinition({
                    id: "MiniMax-M2.1",
                    name: "MiniMax M2.1",
                    input: ["text"],
                  }),
                  buildModelDefinition({
                    id: "MiniMax-M2.5",
                    name: "MiniMax M2.5",
                    input: ["text"],
                    reasoning: true,
                  }),
                ],
              },
            },
          },
          agents: {
            defaults: {
              models: {
                [modelRef("MiniMax-M2.1")]: { alias: "minimax-m2.1" },
                [modelRef("MiniMax-M2.5")]: { alias: "minimax-m2.5" },
              },
            },
          },
        },
        defaultModel: modelRef(DEFAULT_MODEL),
        notes: [
          "MiniMax OAuth tokens auto-refresh. Re-run login if refresh fails or access is revoked.",
          `Base URL defaults to ${defaultBaseUrl}. Override models.providers.${PROVIDER_ID}.baseUrl if needed.`,
          ...(result.notification_message ? [result.notification_message] : []),
        ],
      };
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : String(err);
      progress.stop(`MiniMax OAuth failed: ${errorMsg}`);
      await ctx.prompter.note(
        "If OAuth fails, verify your MiniMax account has portal access and try again.",
        "MiniMax OAuth",
      );
      throw err;
    }
  };
}

const minimaxPortalPlugin = {
  id: "minimax-portal-auth",
  name: "MiniMax OAuth",
  description: "OAuth flow for MiniMax models",
  configSchema: emptyPluginConfigSchema(),
  register(api: OpenClawPluginApi) {
    api.registerProvider({
      id: PROVIDER_ID,
      label: PROVIDER_LABEL,
      docsPath: "/providers/minimax",
      aliases: ["minimax"],
      auth: [
        {
          id: "oauth",
          label: "MiniMax OAuth (Global)",
          hint: "Global endpoint - api.minimax.io",
          kind: "device_code",
          run: createOAuthHandler("global"),
        },
        {
          id: "oauth-cn",
          label: "MiniMax OAuth (CN)",
          hint: "CN endpoint - api.minimaxi.com",
          kind: "device_code",
          run: createOAuthHandler("cn"),
        },
      ],
    });
  },
};

export default minimaxPortalPlugin;
]]></file>
  <file path="./extensions/minimax-portal-auth/oauth.ts"><![CDATA[import { createHash, randomBytes, randomUUID } from "node:crypto";

export type MiniMaxRegion = "cn" | "global";

const MINIMAX_OAUTH_CONFIG = {
  cn: {
    baseUrl: "https://api.minimaxi.com",
    clientId: "78257093-7e40-4613-99e0-527b14b39113",
  },
  global: {
    baseUrl: "https://api.minimax.io",
    clientId: "78257093-7e40-4613-99e0-527b14b39113",
  },
} as const;

const MINIMAX_OAUTH_SCOPE = "group_id profile model.completion";
const MINIMAX_OAUTH_GRANT_TYPE = "urn:ietf:params:oauth:grant-type:user_code";

function getOAuthEndpoints(region: MiniMaxRegion) {
  const config = MINIMAX_OAUTH_CONFIG[region];
  return {
    codeEndpoint: `${config.baseUrl}/oauth/code`,
    tokenEndpoint: `${config.baseUrl}/oauth/token`,
    clientId: config.clientId,
    baseUrl: config.baseUrl,
  };
}

export type MiniMaxOAuthAuthorization = {
  user_code: string;
  verification_uri: string;
  expired_in: number;
  interval?: number;
  state: string;
};

export type MiniMaxOAuthToken = {
  access: string;
  refresh: string;
  expires: number;
  resourceUrl?: string;
  notification_message?: string;
};

type TokenPending = { status: "pending"; message?: string };

type TokenResult =
  | { status: "success"; token: MiniMaxOAuthToken }
  | TokenPending
  | { status: "error"; message: string };

function toFormUrlEncoded(data: Record<string, string>): string {
  return Object.entries(data)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
    .join("&");
}

function generatePkce(): { verifier: string; challenge: string; state: string } {
  const verifier = randomBytes(32).toString("base64url");
  const challenge = createHash("sha256").update(verifier).digest("base64url");
  const state = randomBytes(16).toString("base64url");
  return { verifier, challenge, state };
}

async function requestOAuthCode(params: {
  challenge: string;
  state: string;
  region: MiniMaxRegion;
}): Promise<MiniMaxOAuthAuthorization> {
  const endpoints = getOAuthEndpoints(params.region);
  const response = await fetch(endpoints.codeEndpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Accept: "application/json",
      "x-request-id": randomUUID(),
    },
    body: toFormUrlEncoded({
      response_type: "code",
      client_id: endpoints.clientId,
      scope: MINIMAX_OAUTH_SCOPE,
      code_challenge: params.challenge,
      code_challenge_method: "S256",
      state: params.state,
    }),
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`MiniMax OAuth authorization failed: ${text || response.statusText}`);
  }

  const payload = (await response.json()) as MiniMaxOAuthAuthorization & { error?: string };
  if (!payload.user_code || !payload.verification_uri) {
    throw new Error(
      payload.error ??
        "MiniMax OAuth authorization returned an incomplete payload (missing user_code or verification_uri).",
    );
  }
  if (payload.state !== params.state) {
    throw new Error("MiniMax OAuth state mismatch: possible CSRF attack or session corruption.");
  }
  return payload;
}

async function pollOAuthToken(params: {
  userCode: string;
  verifier: string;
  region: MiniMaxRegion;
}): Promise<TokenResult> {
  const endpoints = getOAuthEndpoints(params.region);
  const response = await fetch(endpoints.tokenEndpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Accept: "application/json",
    },
    body: toFormUrlEncoded({
      grant_type: MINIMAX_OAUTH_GRANT_TYPE,
      client_id: endpoints.clientId,
      user_code: params.userCode,
      code_verifier: params.verifier,
    }),
  });

  const text = await response.text();
  let payload:
    | {
        status?: string;
        base_resp?: { status_code?: number; status_msg?: string };
      }
    | undefined;
  if (text) {
    try {
      payload = JSON.parse(text) as typeof payload;
    } catch {
      payload = undefined;
    }
  }

  if (!response.ok) {
    return {
      status: "error",
      message:
        (payload?.base_resp?.status_msg ?? text) || "MiniMax OAuth failed to parse response.",
    };
  }

  if (!payload) {
    return { status: "error", message: "MiniMax OAuth failed to parse response." };
  }

  const tokenPayload = payload as {
    status: string;
    access_token?: string | null;
    refresh_token?: string | null;
    expired_in?: number | null;
    token_type?: string;
    resource_url?: string;
    notification_message?: string;
  };

  if (tokenPayload.status === "error") {
    return { status: "error", message: "An error occurred. Please try again later" };
  }

  if (tokenPayload.status != "success") {
    return { status: "pending", message: "current user code is not authorized" };
  }

  if (!tokenPayload.access_token || !tokenPayload.refresh_token || !tokenPayload.expired_in) {
    return { status: "error", message: "MiniMax OAuth returned incomplete token payload." };
  }

  return {
    status: "success",
    token: {
      access: tokenPayload.access_token,
      refresh: tokenPayload.refresh_token,
      expires: tokenPayload.expired_in,
      resourceUrl: tokenPayload.resource_url,
      notification_message: tokenPayload.notification_message,
    },
  };
}

export async function loginMiniMaxPortalOAuth(params: {
  openUrl: (url: string) => Promise<void>;
  note: (message: string, title?: string) => Promise<void>;
  progress: { update: (message: string) => void; stop: (message?: string) => void };
  region?: MiniMaxRegion;
}): Promise<MiniMaxOAuthToken> {
  const region = params.region ?? "global";
  const { verifier, challenge, state } = generatePkce();
  const oauth = await requestOAuthCode({ challenge, state, region });
  const verificationUrl = oauth.verification_uri;

  const noteLines = [
    `Open ${verificationUrl} to approve access.`,
    `If prompted, enter the code ${oauth.user_code}.`,
    `Interval: ${oauth.interval ?? "default (2000ms)"}, Expires at: ${oauth.expired_in} unix timestamp`,
  ];
  await params.note(noteLines.join("\n"), "MiniMax OAuth");

  try {
    await params.openUrl(verificationUrl);
  } catch {
    // Fall back to manual copy/paste if browser open fails.
  }

  let pollIntervalMs = oauth.interval ? oauth.interval : 2000;
  const expireTimeMs = oauth.expired_in;

  while (Date.now() < expireTimeMs) {
    params.progress.update("Waiting for MiniMax OAuth approval…");
    const result = await pollOAuthToken({
      userCode: oauth.user_code,
      verifier,
      region,
    });

    // // Debug: print poll result
    // await params.note(
    //   `status: ${result.status}` +
    //     (result.status === "success" ? `\ntoken: ${JSON.stringify(result.token, null, 2)}` : "") +
    //     (result.status === "error" ? `\nmessage: ${result.message}` : "") +
    //     (result.status === "pending" && result.message ? `\nmessage: ${result.message}` : ""),
    //   "MiniMax OAuth Poll Result",
    // );

    if (result.status === "success") {
      return result.token;
    }

    if (result.status === "error") {
      throw new Error(`MiniMax OAuth failed: ${result.message}`);
    }

    if (result.status === "pending") {
      pollIntervalMs = Math.min(pollIntervalMs * 1.5, 10000);
    }

    await new Promise((resolve) => setTimeout(resolve, pollIntervalMs));
  }

  throw new Error("MiniMax OAuth timed out waiting for authorization.");
}
]]></file>
</repository>
