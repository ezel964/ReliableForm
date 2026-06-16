import { onLCP, onINP, onCLS, onTTFB, onFCP, type Metric } from 'web-vitals';

export interface VitalBeacon {
  metric: string;
  value: number;
  id: string;
  rating: string;
  nav: string;
  page: string;
}

export function vitalPayload(m: Metric, page: string): VitalBeacon {
  return {
    metric: m.name,
    value: m.value,
    id: m.id,
    rating: m.rating,
    nav: m.navigationType,
    page,
  };
}

export function reportVitals(page: string, beacon: (b: VitalBeacon) => void): void {
  const send = (m: Metric) => beacon(vitalPayload(m, page));
  onLCP(send);
  onINP(send);
  onCLS(send);
  onTTFB(send);
  onFCP(send);
}
