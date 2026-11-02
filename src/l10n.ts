import { translate, translatePlural } from '@nextcloud/l10n'

// All callers render plain text through Vue interpolation or native dialogs.
// Vue already escapes text; preserve filenames containing <, &, or quotes.
const textOptions = { escape: false, sanitize: false }
type Parameters = Record<string, string | number>

export function t(app: string, message: string, parameters: Parameters = {}): string {
	return translate(app, message, parameters, textOptions)
}

export function n(app: string, singular: string, plural: string, count: number, parameters: Parameters = {}): string {
	return translatePlural(app, singular, plural, count, parameters, textOptions)
}
