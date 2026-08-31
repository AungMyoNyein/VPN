import { describe, expect, it } from 'vitest';

export function calculateUtilization(allocated: number, capacity: number): number {
  if (capacity <= 0) return 0;
  return Math.round((allocated / capacity) * 100);
}

export function isValidCidr(cidr: string): boolean {
  const parts = cidr.split('/');
  if (parts.length !== 2) return false;
  const [ip, prefixStr] = parts;
  const prefix = parseInt(prefixStr, 10);
  if (isNaN(prefix) || prefix < 16 || prefix > 30) return false;

  const octets = ip.split('.');
  if (octets.length !== 4) return false;
  for (const oct of octets) {
    const num = parseInt(oct, 10);
    if (isNaN(num) || num < 0 || num > 255) return false;
  }
  return true;
}

describe('IP Pool Utilities', () => {
  it('calculates utilization percentage correctly', () => {
    expect(calculateUtilization(50, 100)).toBe(50);
    expect(calculateUtilization(0, 100)).toBe(0);
    expect(calculateUtilization(10, 0)).toBe(0);
    expect(calculateUtilization(1, 3)).toBe(33);
  });

  it('validates CIDR strings', () => {
    expect(isValidCidr('10.200.20.0/24')).toBe(true);
    expect(isValidCidr('10.200.0.0/16')).toBe(true);
    expect(isValidCidr('10.200.20.0/32')).toBe(false); // prefix > 30
    expect(isValidCidr('10.200.20.0/8')).toBe(false); // prefix < 16
    expect(isValidCidr('invalid-cidr')).toBe(false);
    expect(isValidCidr('256.200.20.0/24')).toBe(false);
  });
});
