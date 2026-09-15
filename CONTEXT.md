# Portable Drupal site

This project distributes Drupal CMS for installation and use with persistent site data.

## Language

**Packaged site**:
A distributable Drupal CMS application that begins with installation on first use.
_Avoid_: Preconfigured website

**Site template**:
The predefined starting configuration applied when a site is installed.

**Site data**:
The persistent information belonging to an installed site, including its content and uploaded files.

## Relationships

- A **Packaged site** includes exactly one **Site template** for installation: Byte.
- Installation of a **Packaged site** creates **Site data**.
- **Site data** survives replacement of the **Packaged site** with an updated release.

## Example dialogue

> Developer: "Does the packaged site arrive with an installed website?"
> User: "It starts the installation process. Once installed, its site data persists across upgrades."
