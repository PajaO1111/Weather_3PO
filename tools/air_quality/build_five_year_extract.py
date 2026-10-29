#!/usr/bin/env python3
"""Build a compact, versioned GeoJSON extract from a ČHMÚ five-year archive."""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import math
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import shapefile
from pyproj import Transformer


ARCHIVE_NAME = "sit1000_5lprum_20_24_CR_UTM.zip"
BOUNDARY_CODE = 3508
BOUNDARY_NAME = "Most"
PERIOD = "2020–2024"
DATASET_URL = (
    "https://opendata.chmi.cz/air_quality/products/5year_avg/"
    "sit1000_5lprum_20_24_CR_UTM.zip"
)
BOUNDARY_URL = (
    "https://ags.cuzk.gov.cz/arcgis/rest/services/RUIAN/MapServer/15/"
    "query?where=nazev%3D%27Most%27&outFields=nazev%2Ckod&returnGeometry=true"
    "&outSR=4326&f=geojson"
)
LAYERS = {
    "annual": {
        "directory": "petileti20-24_CR_rp_-_podle_zakona_201",
        "stem": "sit1000_5lprum_20_24_UTM33N_rp",
        "fields": {
            "NO2": "NO2_rp_5l",
            "BZN": "BZN_rp_5l",
            "BaP": "BaP_rp_5l",
            "PM10": "PM10_rp_5l",
            "PM2_5": "PM25_rp_5l",
            "As": "As_rp_5l",
            "Cd": "Cd_rp_5l",
            "Ni": "Ni_rp_5l",
            "Pb": "Pb_rp_5l",
        },
    },
    "24h": {
        "directory": "petileti20-24_CR_24hod",
        "stem": "sit1000_5lprum_20_24_UTM33N_24hod",
        "fields": {"SO2": "SO2h24_5l", "PM10": "PM10h24_5l"},
    },
}


def fail(message: str) -> None:
    raise ValueError(message)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def ring_centroid(points: list[tuple[float, float]]) -> tuple[float, float, float]:
    cross_sum = 0.0
    x_sum = 0.0
    y_sum = 0.0
    for index, (x1, y1) in enumerate(points):
        x2, y2 = points[(index + 1) % len(points)]
        cross = x1 * y2 - x2 * y1
        cross_sum += cross
        x_sum += (x1 + x2) * cross
        y_sum += (y1 + y2) * cross
    if abs(cross_sum) < 1e-8:
        return 0.0, 0.0, 0.0
    return x_sum / (3.0 * cross_sum), y_sum / (3.0 * cross_sum), cross_sum / 2.0


def shape_centroid(shape: shapefile.Shape) -> tuple[float, float]:
    parts = list(shape.parts) + [len(shape.points)]
    area_sum = 0.0
    x_sum = 0.0
    y_sum = 0.0
    for start, end in zip(parts, parts[1:]):
        points = shape.points[start:end]
        if len(points) < 3:
            continue
        x, y, area = ring_centroid(points)
        area_sum += area
        x_sum += x * area
        y_sum += y * area
    if abs(area_sum) < 1e-8:
        fail("ČHMÚ grid cell has no usable polygon area.")
    return x_sum / area_sum, y_sum / area_sum


def point_in_ring(point: tuple[float, float], ring: list[tuple[float, float]]) -> bool:
    x, y = point
    inside = False
    previous = ring[-1]
    for current in ring:
        x1, y1 = previous
        x2, y2 = current
        if (y1 > y) != (y2 > y):
            crossing_x = (x2 - x1) * (y - y1) / (y2 - y1) + x1
            if x < crossing_x:
                inside = not inside
        previous = current
    return inside


def point_in_boundary(
    point: tuple[float, float],
    polygons: list[list[list[tuple[float, float]]]],
    bounds: tuple[float, float, float, float],
) -> bool:
    if point[0] < bounds[0] or point[0] > bounds[2] or point[1] < bounds[1] or point[1] > bounds[3]:
        return False
    for rings in polygons:
        if rings and point_in_ring(point, rings[0]) and not any(
            point_in_ring(point, hole) for hole in rings[1:]
        ):
            return True
    return False


def read_boundary(
    path: Path, to_utm: Transformer
) -> tuple[dict[str, Any], list[list[list[tuple[float, float]]]], tuple[float, float, float, float]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    features = data.get("features")
    if not isinstance(features, list) or len(features) != 1:
        fail("Boundary GeoJSON must contain exactly one RÚIAN district feature.")
    feature = features[0]
    properties = feature.get("properties", {})
    if properties.get("nazev") != BOUNDARY_NAME or properties.get("kod") != BOUNDARY_CODE:
        fail("Boundary GeoJSON is not the official RÚIAN district Most (code 3508).")
    geometry = feature.get("geometry", {})
    coordinates = geometry.get("coordinates")
    if geometry.get("type") == "Polygon":
        coordinate_polygons = [coordinates]
    elif geometry.get("type") == "MultiPolygon":
        coordinate_polygons = coordinates
    else:
        fail("District boundary must be a Polygon or MultiPolygon.")
    polygons = []
    for polygon in coordinate_polygons:
        rings = []
        for ring in polygon:
            if not isinstance(ring, list) or len(ring) < 4:
                fail("District boundary contains an invalid ring.")
            rings.append([to_utm.transform(float(point[0]), float(point[1])) for point in ring])
        polygons.append(rings)
    points = [point for polygon in polygons for ring in polygon for point in ring]
    bounds = (
        min(point[0] for point in points),
        min(point[1] for point in points),
        max(point[0] for point in points),
        max(point[1] for point in points),
    )
    return feature, polygons, bounds


def read_layer(
    archive: zipfile.ZipFile,
    definition: dict[str, Any],
    from_utm: Transformer,
    boundary: list[list[list[tuple[float, float]]]],
    boundary_bounds: tuple[float, float, float, float],
) -> dict[int, dict[str, Any]]:
    stem = definition["stem"]
    directory = definition["directory"]
    names = {name.rsplit("/", 1)[-1]: name for name in archive.namelist()}
    required = {f"{stem}.shp", f"{stem}.shx", f"{stem}.dbf", f"{stem}.prj"}
    if not required.issubset(names):
        fail(f"CHMI archive is missing shapefile components for {stem}.")
    projection = archive.read(names[f"{stem}.prj"]).decode("ascii", errors="ignore")
    if "WGS_1984_UTM_Zone_33N" not in projection:
        fail(f"Unexpected CRS in {stem}.prj; expected WGS84 / UTM zone 33N.")
    reader = shapefile.Reader(
        shp=io.BytesIO(archive.read(names[f"{stem}.shp"])),
        shx=io.BytesIO(archive.read(names[f"{stem}.shx"])),
        dbf=io.BytesIO(archive.read(names[f"{stem}.dbf"])),
        encoding="utf-8",
    )
    fields = {field[0] for field in reader.fields[1:]}
    if not set(definition["fields"].values()).issubset(fields):
        fail(f"CHMI archive fields have changed in {stem}.")
    if len(reader) != 80096:
        fail(f"Expected 80,096 source cells in {stem}; found {len(reader)}.")

    selected: dict[int, dict[str, Any]] = {}
    for record, shape in zip(reader.iterRecords(), reader.iterShapes()):
        record_values = record.as_dict()
        cell_id = record_values.get("CISLO")
        if not isinstance(cell_id, int):
            fail(f"Invalid grid cell identifier in {stem}.")
        if cell_id in selected:
            fail(f"Duplicate grid cell identifier in {stem}.")
        center = shape_centroid(shape)
        if not point_in_boundary(center, boundary, boundary_bounds):
            continue
        coordinates = []
        parts = list(shape.parts) + [len(shape.points)]
        for start, end in zip(parts, parts[1:]):
            ring = []
            for x, y in shape.points[start:end]:
                lon, lat = from_utm.transform(x, y)
                if not math.isfinite(lon) or not math.isfinite(lat):
                    fail(f"Invalid transformed grid coordinate in {stem}.")
                ring.append([round(lon, 6), round(lat, 6)])
            coordinates.append(ring)
        values = {}
        for component, field in definition["fields"].items():
            value = record_values.get(field)
            if value is not None and (not isinstance(value, (int, float)) or not math.isfinite(value) or value < 0):
                fail(f"Invalid concentration value in field {field}.")
            values[component] = value
        selected[cell_id] = {
            "type": "Feature",
            "id": cell_id,
            "geometry": {"type": "Polygon", "coordinates": coordinates},
            "properties": {"gridId": cell_id, "layers": {directory: values}},
        }
    return selected


def build(archive_path: Path, boundary_path: Path, output_path: Path, generated_at: str) -> int:
    if archive_path.name != ARCHIVE_NAME:
        fail(f"Expected official source archive named {ARCHIVE_NAME}.")
    to_utm = Transformer.from_crs("EPSG:4326", "EPSG:32633", always_xy=True)
    from_utm = Transformer.from_crs("EPSG:32633", "EPSG:4326", always_xy=True)
    boundary_feature, boundary_polygons, boundary_bounds = read_boundary(boundary_path, to_utm)
    with zipfile.ZipFile(archive_path) as archive:
        layers = {
            key: read_layer(archive, definition, from_utm, boundary_polygons, boundary_bounds)
            for key, definition in LAYERS.items()
        }
    if layers["annual"].keys() != layers["24h"].keys():
        fail("Annual and 24-hour layers do not cover the same Most grid cells.")
    features = []
    for cell_id in sorted(layers["annual"]):
        feature = layers["annual"][cell_id]
        feature["properties"]["layers"] = {
            "annual": feature["properties"]["layers"][LAYERS["annual"]["directory"]],
            "24h": layers["24h"][cell_id]["properties"]["layers"][LAYERS["24h"]["directory"]],
        }
        features.append(feature)
    if not features:
        fail("No CHMI grid cell centers fall inside Most district.")
    payload = {
        "type": "FeatureCollection",
        "metadata": {
            "dataset": "ČHMÚ five-year air-quality grid",
            "period": PERIOD,
            "generatedAt": generated_at,
            "sourcePublishedAt": "2025-11-26",
            "district": {"name": BOUNDARY_NAME, "ruianCode": BOUNDARY_CODE},
            "gridResolutionMeters": 1000,
            "crs": "EPSG:4326",
            "selection": "Grid-cell polygon centroid inside the official district boundary; boundary cells are not clipped.",
            "source": DATASET_URL,
            "sourceArchiveSha256": sha256(archive_path),
            "boundarySource": BOUNDARY_URL,
            "boundarySourceSha256": sha256(boundary_path),
            "boundaryLicense": "CC BY 4.0",
            "boundaryAttribution": "ČÚZK, RÚIAN, okres Most (code 3508)",
            "products": {
                "annual": {
                    "label": "Roční průměry · pětiletý produkt 2020–2024",
                    "unit": "µg/m³",
                    "fields": LAYERS["annual"]["fields"],
                },
                "24h": {
                    "label": "24hodinová vrstva · pětiletý produkt 2020–2024",
                    "unit": "µg/m³",
                    "fields": LAYERS["24h"]["fields"],
                },
            },
            "attribution": "ČHMÚ, pětileté mapy kvality ovzduší 2020–2024",
            "license": "CC BY 4.0",
        },
        "boundary": boundary_feature["geometry"],
        "features": features,
    }
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(payload, ensure_ascii=False, separators=(",", ":"), allow_nan=False) + "\n",
        encoding="utf-8",
    )
    return len(features)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", required=True, type=Path)
    parser.add_argument("--boundary", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument(
        "--generated-at",
        default=datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    )
    args = parser.parse_args()
    try:
        count = build(args.archive, args.boundary, args.output, args.generated_at)
    except (OSError, ValueError, KeyError, zipfile.BadZipFile) as error:
        print(f"Error: {error}", file=sys.stderr)
        return 1
    print(f"Wrote {count} grid cells to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
