import React from 'react';

const SEGMENT_MAP = {
	'0': ['a', 'b', 'c', 'd', 'e', 'f'],
	'1': ['b', 'c'],
	'2': ['a', 'b', 'd', 'e', 'g'],
	'3': ['a', 'b', 'c', 'd', 'g'],
	'4': ['b', 'c', 'f', 'g'],
	'5': ['a', 'c', 'd', 'f', 'g'],
	'6': ['a', 'c', 'd', 'e', 'f', 'g'],
	'7': ['a', 'b', 'c'],
	'8': ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
	'9': ['a', 'b', 'c', 'd', 'f', 'g'],
	A: ['a', 'b', 'c', 'e', 'f', 'g'],
	C: ['a', 'd', 'e', 'f'],
	E: ['a', 'd', 'e', 'f', 'g'],
	I: ['e', 'f'],
	L: ['d', 'e', 'f'],
	O: ['a', 'b', 'c', 'd', 'e', 'f'],
	P: ['a', 'b', 'e', 'f', 'g'],
	R: ['e', 'g'],
	S: ['a', 'c', 'd', 'f', 'g'],
	U: ['b', 'c', 'd', 'e', 'f'],
	V: ['c', 'd', 'e'],
	Y: ['b', 'c', 'd', 'f', 'g'],
};

const SEGMENTS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];

function SevenSegmentText({ text = '', variant = 'board', className = '' }) {
	const chars = String(text).toUpperCase().split('');

	return (
		<span className={`seven-seg seven-seg-${variant} ${className}`.trim()} aria-label={text}>
			{chars.map((char, index) => {
				if (char === ' ') {
					return <span key={`${char}-${index}`} className="seven-seg-space" />;
				}

				const activeSegments = SEGMENT_MAP[char];
				if (!activeSegments) {
					return (
						<span key={`${char}-${index}`} className="seven-seg-fallback">
							{char}
						</span>
					);
				}

				return (
					<span key={`${char}-${index}`} className="seven-seg-char" data-char={char}>
						{SEGMENTS.map((segment) => (
							<span
								key={segment}
								className={`seven-seg-segment seven-seg-${segment}${activeSegments.includes(segment) ? ' on' : ''}`}
							/>
						))}
					</span>
				);
			})}
		</span>
	);
}

export default SevenSegmentText;
